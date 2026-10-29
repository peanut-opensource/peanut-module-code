#!/bin/sh
set -eu

# The caller supplies a verified runtime image ID. Composer runs only against
# this server's composer.lock and never executes package plugins or scripts.
[ "$#" -eq 3 ] || { echo 'Usage: prepare-vendor.sh start|prepared SERVER_DIR PHP_IMAGE' >&2; exit 64; }
mode=$1
server=$2
php_image=$3
case "$mode" in start|prepared) ;; *) exit 64 ;; esac
case "$server" in /*) ;; *) exit 64 ;; esac
[ -d "$server" ] && [ ! -L "$server" ] || { echo 'server root is unsafe' >&2; exit 1; }
printf '%s' "$php_image" | grep -Eq '^(sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})$' || {
    echo 'PHP_IMAGE must be an immutable digest reference' >&2; exit 1;
}
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
command -v python3 >/dev/null 2>&1 || { echo 'python3 is required for vendor verification' >&2; exit 1; }
set --
if [ -f "$server/.vendor-inputs.json" ] || [ -L "$server/.vendor-inputs.json" ]; then
    set -- --inputs "$server/.vendor-inputs.json"
fi
if python3 "$script_dir/vendor-state.py" check --server "$server" "$@"; then
    exit 0
fi
python3 - "$script_dir/vendor-state.py" "$server" <<'PY'
import importlib.util
from pathlib import Path
import sys
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('native_vendor_inputs', sys.argv[1])
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
server = Path(sys.argv[2]); proof = server / '.vendor-inputs.json'
module.check_lock(server, proof if proof.exists() or proof.is_symlink() else None)
PY
if [ -e "$server/vendor" ] || [ -L "$server/vendor" ]; then
    echo 'vendor is present but incomplete or damaged' >&2
else
    echo 'vendor is missing' >&2
fi
if [ "$mode" = start ] && { [ -e "$server/private/installation/installed.json" ] || [ -L "$server/private/installation/installed.json" ]; }; then
    echo 'installed instance requires a stopped-writer maintenance update to repair vendor; normal start leaves it unchanged' >&2
    exit 1
fi
if [ "$mode" = prepared ]; then
    case "$server" in */prepared/server) ;; *) echo 'prepared mode requires a prepared/server workspace' >&2; exit 1 ;; esac
fi
docker image inspect "$php_image" >/dev/null
work=$server
staging=''
if [ "$mode" = start ]; then
    # A fresh full APP already owns its source tree. Only dependencies are installed here.
    # Existing or damaged vendor and installed instances still require maintenance.
    if [ -f "$server/../.peanut/application-manifest.json" ] && [ ! -L "$server/../.peanut" ] \
        && [ ! -L "$server/../.peanut/application-manifest.json" ] \
        && [ -f "$server/../release-versions.json" ] && [ ! -L "$server/../release-versions.json" ] \
        && [ ! -e "$server/.peanut/release-identity.json" ] && [ ! -L "$server/.peanut/release-identity.json" ]; then
        [ ! -e "$server/vendor" ] && [ ! -L "$server/vendor" ] || {
            echo 'source APP vendor is present but invalid; repair requires stopped-writer maintenance' >&2; exit 1;
        }
    else
        runtime="$server/runtime"
        [ -d "$runtime" ] && [ ! -L "$runtime" ] || { echo 'runtime staging root is unsafe' >&2; exit 1; }
        staging="$runtime/vendor-stage-$$"
        mkdir -m 700 -- "$staging"
        work="$staging/server"
        python3 "$script_dir/vendor-state.py" stage --server "$server" --destination "$work"
    fi
else
    [ ! -L "$server/vendor" ] || { echo 'prepared vendor is unsafe' >&2; exit 1; }
    if [ -d "$server/vendor" ]; then
        rm -r -- "$server/vendor"
    fi
fi
archive_mount=$(python3 - "$script_dir/vendor-state.py" "$work" <<'PY'
import importlib.util
from pathlib import Path
import sys
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('native_vendor', sys.argv[1])
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
server = Path(sys.argv[2]); proof = server / '.vendor-inputs.json'
inputs = proof if proof.exists() or proof.is_symlink() else None
module.check_lock(server, inputs)
archives = module.local_archives(server, inputs)
if len(archives) > 1: raise ValueError('only one canonical Core archive is supported')
for relative in archives:
    # Ordinary server releases carry the archive inside the /srv bind. The old
    # source projection keeps its separately verified /packages read-only mount.
    source = module.archive_path(server, relative)
    if ',' in str(source) or any(ord(c) < 32 for c in str(source)): raise ValueError('ambiguous archive mount')
    if source.is_relative_to(server): continue
    print('type=bind,src=' + str(source) + ',dst=/' + relative + ',readonly')
PY
)
set -- docker run --rm --network bridge --mount "type=bind,src=$work,dst=/srv"
if [ -n "$archive_mount" ]; then set -- "$@" --mount "$archive_mount"; fi
if ! "$@" --workdir /srv --entrypoint /bin/sh "$php_image" -ec '
      composer --version --no-ansi | grep -Eq "^Composer version 2[.]10[.]2 "
      printf "%s  %s\n" 5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027 /usr/local/bin/composer | sha256sum -c -
      COMPOSER_ALLOW_SUPERUSER=1 composer validate --no-check-publish
      COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins
      php docker/scripts/prepare-vendor.php
      COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev
    '
then
    exit 1
fi
set --
if [ -f "$work/.vendor-inputs.json" ] || [ -L "$work/.vendor-inputs.json" ]; then set -- --inputs "$work/.vendor-inputs.json"; fi
python3 "$script_dir/vendor-state.py" seal --server "$work" "$@"
python3 "$script_dir/vendor-state.py" check --server "$work" "$@"

if [ -n "$staging" ]; then
    old="$runtime/vendor-previous-$$"
    if [ -e "$server/vendor" ] || [ -L "$server/vendor" ]; then
        [ -d "$server/vendor" ] && [ ! -L "$server/vendor" ] || { echo 'existing vendor is unsafe' >&2; exit 1; }
        mv -- "$server/vendor" "$old"
    fi
    if ! mv -- "$work/vendor" "$server/vendor"; then
        [ ! -d "$old" ] || mv -- "$old" "$server/vendor"
        echo 'prepared vendor could not be activated' >&2
        exit 1
    fi
    python3 "$script_dir/vendor-state.py" check --server "$server"
    rm -r -- "$staging"
fi
