#!/bin/sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
DOCKER_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)
SERVER_DIR=$(CDPATH= cd -- "$DOCKER_DIR/.." && pwd -P)
file_links() { stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1"; }
file_mode() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"; }
[ -f "$DOCKER_DIR/.env" ] && [ ! -L "$DOCKER_DIR/.env" ] && [ "$(file_links "$DOCKER_DIR/.env")" = 1 ] || {
    echo "server/docker/.env is required; run python3 server/docker/scripts/configure-runtime.py --php-image=<prepared-immutable-image> for a new instance" >&2
    exit 1
}
[ "$(file_mode "$DOCKER_DIR/.env")" = 600 ] || { echo "server/docker/.env must have mode 0600" >&2; exit 1; }
umask 077
for path in "$SERVER_DIR/public" "$SERVER_DIR/private" "$SERVER_DIR/docker" \
    "$DOCKER_DIR/mysql" "$DOCKER_DIR/secrets" "$SERVER_DIR/runtime" \
    "$SERVER_DIR/public/storage" "$SERVER_DIR/private/storage" \
    "$SERVER_DIR/private/installation" "$SERVER_DIR/private/resources" "$SERVER_DIR/private/maintenance" \
    "$SERVER_DIR/private/resources/pending" "$SERVER_DIR/runtime/cache" "$SERVER_DIR/runtime/log" \
    "$SERVER_DIR/runtime/session" "$SERVER_DIR/runtime/temp" "$SERVER_DIR/runtime/storage" \
    "$SERVER_DIR/runtime/generator" "$SERVER_DIR/runtime/file"; do
    [ ! -L "$path" ] && { [ ! -e "$path" ] || [ -d "$path" ]; } || {
        echo "protected directory is linked or has an unsafe type: $path" >&2; exit 1;
    }
done
for path in "$SERVER_DIR/private/installation/installed.json" \
    "$DOCKER_DIR/secrets/install-token"; do
    if [ -e "$path" ] || [ -L "$path" ]; then
        [ -f "$path" ] && [ ! -L "$path" ] && [ "$(file_links "$path")" = 1 ] || {
            echo "protected state file is unsafe: $path" >&2; exit 1;
        }
    fi
done

if [ -e "$SERVER_DIR/.env" ] || [ -L "$SERVER_DIR/.env" ]; then
    [ -f "$SERVER_DIR/.env" ] && [ ! -L "$SERVER_DIR/.env" ] && [ "$(file_links "$SERVER_DIR/.env")" = 1 ] || { echo "server/.env is unsafe" >&2; exit 1; }
    mode=$(file_mode "$SERVER_DIR/.env")
    [ "$mode" = "600" ] || { echo "server/.env must have mode 0600" >&2; exit 1; }
fi
database_source=$(python3 - "$SERVER_DIR" "$@" <<'PY'
import importlib.util, pathlib, sys
sys.dont_write_bytecode = True
server = pathlib.Path(sys.argv[1])
spec = importlib.util.spec_from_file_location('native_compose', server / 'docker/scripts/configure-collaboration.py')
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
arguments = module.compose_arguments(server) + sys.argv[2:]
print('existing' if module.registered_database_services(server, arguments) else 'bundled')
PY
)
php_image=$(sed -n 's/^PHP_IMAGE=//p' "$DOCKER_DIR/.env" | tail -n 1)
printf '%s' "$php_image" | grep -Eq '^(sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})$' || {
    echo "PHP_IMAGE must be a prepared immutable image ID" >&2
    exit 1
}
# Only this composition's bundled database needs a root credential or data directory.
if [ "$database_source" = bundled ]; then
mysql_image=$(sed -n 's/^MYSQL_IMAGE=//p' "$DOCKER_DIR/.env" | tail -n 1)
printf '%s' "$mysql_image" | grep -Eq '^(sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})$' || {
    echo "bundled MYSQL_IMAGE must be a prepared immutable image ID" >&2; exit 1;
}
# Canonical Docker root credential lives only in server/docker/.env. Older
# packages stored the same value in docker/secrets/mysql-root-password; consume
# that legacy file once, atomically migrate it, then remove it.
installed="$SERVER_DIR/private/installation/installed.json"
legacy_root_secret="$DOCKER_DIR/secrets/mysql-root-password"
root_count=$(awk -F= '$1 == "MYSQL_ROOT_PASSWORD" { count++ } END { print count + 0 }' "$DOCKER_DIR/.env")
[ "$root_count" -le 1 ] || { echo "server/docker/.env contains duplicate MYSQL_ROOT_PASSWORD" >&2; exit 1; }
if [ -e "$legacy_root_secret" ] || [ -L "$legacy_root_secret" ]; then
    [ -f "$legacy_root_secret" ] && [ ! -L "$legacy_root_secret" ] && [ "$(file_links "$legacy_root_secret")" = 1 ] \
        && [ "$(file_mode "$legacy_root_secret")" = 600 ] || {
        echo "legacy MySQL root credential file is unsafe" >&2; exit 1;
    }
    legacy_password=$(tr -d '\r\n' < "$legacy_root_secret")
    [ -n "$legacy_password" ] || { echo "legacy MySQL root credential is empty" >&2; exit 1; }
    if [ "$root_count" -eq 1 ]; then
        canonical_password=$(awk -F= '$1 == "MYSQL_ROOT_PASSWORD" { sub(/^[^=]*=/, ""); print; exit }' "$DOCKER_DIR/.env")
        [ "$canonical_password" = "$legacy_password" ] || { echo "canonical and legacy MySQL root credentials conflict" >&2; exit 1; }
    else
        temporary="$DOCKER_DIR/.env.root-migration.$$"
        trap 'rm -f "$temporary"' EXIT HUP INT TERM
        cat "$DOCKER_DIR/.env" > "$temporary"
        printf 'MYSQL_ROOT_PASSWORD=%s\n' "$legacy_password" >> "$temporary"
        chmod 600 "$temporary"
        mv "$temporary" "$DOCKER_DIR/.env"
        trap - EXIT HUP INT TERM
        root_count=1
    fi
    rm -f "$legacy_root_secret"
    unset legacy_password canonical_password
fi
[ "$root_count" -eq 1 ] || {
    echo "server/docker/.env is missing MYSQL_ROOT_PASSWORD; refusing to invent a credential for existing or new data" >&2
    exit 1
}
root_password=$(awk -F= '$1 == "MYSQL_ROOT_PASSWORD" { sub(/^[^=]*=/, ""); print; exit }' "$DOCKER_DIR/.env")
[ -n "$root_password" ] || { echo "server/docker/.env contains an empty MYSQL_ROOT_PASSWORD" >&2; exit 1; }
unset root_password
fi
mkdir -p "$SERVER_DIR/runtime"
"$SCRIPT_DIR/prepare-vendor.sh" start "$SERVER_DIR" "$php_image"

if [ "$database_source" = bundled ]; then mkdir -p "$DOCKER_DIR/mysql"; fi
mkdir -p "$DOCKER_DIR/secrets" \
    "$SERVER_DIR/public/storage" "$SERVER_DIR/private/storage" \
    "$SERVER_DIR/private/installation" "$SERVER_DIR/private/resources" "$SERVER_DIR/private/maintenance" \
    "$SERVER_DIR/private/resources/pending" "$SERVER_DIR/runtime/cache" "$SERVER_DIR/runtime/log" \
    "$SERVER_DIR/runtime/session" "$SERVER_DIR/runtime/temp" "$SERVER_DIR/runtime/storage" \
    "$SERVER_DIR/runtime/generator" "$SERVER_DIR/runtime/file"
installed="$SERVER_DIR/private/installation/installed.json"

token="$DOCKER_DIR/secrets/install-token"
if [ ! -e "$installed" ]; then
    if [ ! -f "$token" ]; then
        for marker in executing.json baseline.json migration.json deployment.json; do
            [ ! -e "$SERVER_DIR/private/installation/$marker" ] && [ ! -L "$SERVER_DIR/private/installation/$marker" ] || {
                echo "installation state exists without its original token" >&2; exit 1;
            }
        done
        command -v openssl >/dev/null 2>&1 || { echo "openssl is required to generate the installation token" >&2; exit 1; }
        temporary="$token.tmp-$$"
        openssl rand -hex 32 > "$temporary"
        chmod 600 "$temporary"
        mv "$temporary" "$token"
    fi
    [ "$(file_mode "$token")" = 600 ] || { echo "installation token must have mode 0600" >&2; exit 1; }
else
    rm -f "$token"
fi

cd "$DOCKER_DIR"
python3 - "$SERVER_DIR" "$@" <<'PY'
import importlib.util, pathlib, sys
sys.dont_write_bytecode=True
server=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('collaboration_owner',server/'docker/scripts/configure-collaboration.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
module.start(server,sys.argv[2:])
PY
if [ ! -e "$installed" ]; then
    printf 'Peanut installation bootstrap is active. Read the one-time token from: %s\n' "$DOCKER_DIR/secrets/install-token"
    printf 'Open /install/ on the configured HTTP endpoint. The token value is not printed to logs.\n'
fi
