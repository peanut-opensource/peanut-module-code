# Functions shared by the paired backup/restore workers. Production supplies
# the registered server/target paths and uses its pinned PHP image for extraction.
pack_public_archive() {
  local server="$1" archive="$2"
  local members=(-C "$server/storage" public)
  if [[ -d "$server/public/uploads" ]]; then members+=(-C "$server/public" uploads); fi
  sudo -n tar --one-file-system --numeric-owner -czf "$archive" "${members[@]}"
}

verify_storage_archives() {
  sudo -n python3 - "$1" "$2" <<'PY'
import pathlib, sys, tarfile
root = pathlib.Path(sys.argv[1])
layout = sys.argv[2]
for name in ("php-storage.tar.gz", "php-private-storage.tar.gz", "php-installation.tar.gz"):
    seen = set()
    with tarfile.open(root / name, "r:gz") as archive:
        for entry in archive:
            raw = entry.name
            while raw.startswith("./"):
                raw = raw[2:]
            path = pathlib.PurePosixPath(raw)
            if (raw.startswith("/") or ".." in path.parts or "\\" in raw or "//" in raw
                    or any(ord(c) < 32 for c in raw) or not (entry.isdir() or entry.isfile())
                    or (not path.parts and not entry.isdir())):
                raise SystemExit("unsafe backup archive member")
            if path in seen:
                raise SystemExit("duplicate backup archive member")
            seen.add(path)
            if name == "php-storage.tar.gz" and layout != "flat":
                if not path.parts or path.parts[0] not in (layout, "uploads"):
                    raise SystemExit("public archive includes program files")
                if len(path.parts) == 1 and not entry.isdir():
                    raise SystemExit("invalid public archive directory")
    if name == "php-storage.tar.gz" and layout != "flat" and pathlib.PurePosixPath(layout) not in seen:
        raise SystemExit("missing public storage archive directory")
PY
}

extract_storage_archive() {
  local archive="$1" destination="$2" image="$3"
  sudo -n gzip -dc "$archive" | sudo -n docker run --rm --network none -i \
    --mount "type=bind,source=$destination,target=/restore" \
    --entrypoint tar "$image" -xf - -C /restore
}

restore_storage_archives() {
  local backup="$1" layout="$2" server="$3" private_directory="$4" image="$5" destination
  local archive="$backup/php-storage.tar.gz"
  sudo -n install -d -m 0700 "$private_directory" "$server/public"
  extract_storage_archive "$backup/php-private-storage.tar.gz" "$private_directory" "$image"
  if [[ "$layout" == storage ]]; then
    # The paired v4 SQL references public/storage and private/storage.
    extract_storage_archive "$archive" "$server/public" "$image"
    return
  fi
  sudo -n install -d -m 0700 "$server/storage"
  if [[ "$layout" == flat ]]; then
    destination="$server/storage/public"
    sudo -n mkdir -m 0700 "$destination"
  else
    destination="$server/public"
  fi
  extract_storage_archive "$archive" "$destination" "$image"
  if [[ "$layout" == public ]]; then
    sudo -n mv -- "$destination/$layout" "$server/storage/public"
  fi
  sudo -n ln -s ../storage/public "$server/public/storage"
}
