#!/bin/sh
# Run only as the root PHP container entrypoint, against its fixed server mount.
set -eu
case "${1:-}" in ""|--readonly-http) ;; *) exit 64 ;; esac

native_root=/var/www/peanut-admin/server
if [ "${1:-}" = --readonly-http ]; then native_root=/run/peanut-owner/server; fi
root=$native_root
if [ "${PEANUT_PERMISSION_TEST:-0}" = 1 ]; then
    root=${PEANUT_SERVER_ROOT:-}
    case "$root" in /tmp/phase1-c-*/server|/tmp/*/phase1-c-*/server|/var/tmp/phase1-c-*/server|/var/tmp/*/phase1-c-*/server) ;; *)
        echo 'synthetic server root is outside the task fixture' >&2; exit 1 ;;
    esac
fi
[ "$(id -u)" -eq 0 ] || { echo 'permission preparation requires container root' >&2; exit 1; }
[ -d "$root" ] && [ ! -L "$root" ] || { echo 'server root is unsafe' >&2; exit 1; }
root=$(cd "$root" && pwd -P)
[ "$root" = "${PEANUT_SERVER_ROOT:-$native_root}" ] || { echo 'server root must be canonical' >&2; exit 1; }
app_uid=$(id -u www-data)
app_gid=$(id -g www-data)
[ "$app_uid" -ne 0 ] && [ "$app_gid" -ne 0 ] || exit 1
cd "$root"

stat_dir() { stat -c '%d %i %u %g %a' "$1"; }
safe_dir() {
    [ -d "$1" ] && [ ! -L "$1" ] || { echo "unsafe directory: $1" >&2; exit 1; }
    mode=$(stat -c %a "$1")
    [ $((0$mode & 0002)) -eq 0 ] || { echo "world-writable directory: $1" >&2; exit 1; }
}
safe_file() {
    [ ! -e "$1" ] && [ ! -L "$1" ] && return 0
    [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c %h "$1")" = 1 ] || { echo "unsafe file: $1" >&2; exit 1; }
    owner=$(stat -c %u "$1")
    [ "$owner" -eq 0 ] || [ "$owner" -eq "$root_uid" ] || [ "$owner" -eq "$app_uid" ] || {
        echo "unknown file owner: $1" >&2; exit 1;
    }
    mode=$(stat -c %a "$1")
    case "$1" in
        private/installation/execution.lock)
            [ $((0$mode & 0022)) -eq 0 ] || { echo "writable installation lock: $1" >&2; exit 1; } ;;
        *) [ $((0$mode & 0007)) -eq 0 ] || { echo "exposed private file: $1" >&2; exit 1; } ;;
    esac
    if [ $((0$mode & 0070)) -ne 0 ]; then
        case "$1" in
            private/installation/*) [ "$(stat -c %g "$1")" -eq "$app_gid" ] || [ "$1" = private/installation/execution.lock ] ;;
            *) false ;;
        esac || { echo "unexpected private file group access: $1" >&2; exit 1; }
    fi
}

safe_dir .
root_uid=$(stat -c %u .)
[ "$root_uid" -ne "$app_uid" ] || { echo 'application user must not own the server code root' >&2; exit 1; }
safe_dir docker
safe_dir docker/secrets
secrets_owner=$(stat -c %u docker/secrets)
secrets_mode=$(stat -c %a docker/secrets)
[ "$secrets_owner" -ne "$app_uid" ] && [ $((0$secrets_mode & 0077)) -eq 0 ] || {
    echo 'docker/secrets is accessible to the application user' >&2; exit 1;
}
state=docker/secrets/install-root-permissions
safe_file "$state"
if [ -e "$state" ]; then
    [ "$(stat -c %u "$state")" -eq 0 ] && [ "$(stat -c %a "$state")" = 600 ] || {
        echo 'permission state is not root-only' >&2; exit 1;
    }
fi
for path in docker/secrets/install-token; do
    if [ -e "$path" ] || [ -L "$path" ]; then
        [ -f "$path" ] && [ ! -L "$path" ] && [ "$(stat -c %h "$path")" = 1 ] &&
            [ "$(stat -c %u "$path")" -ne "$app_uid" ] &&
            [ "$(stat -c %a "$path")" = 600 ] || {
            echo "container secret is exposed: $path" >&2; exit 1;
        }
    fi
done

# Existing installation identities and partial configuration always close the window.
for path in private/installation/installed.json private/installation/executing.json \
    runtime/installation/installed.json runtime/installation/executing.json \
    runtime/installation/baseline.json; do
    [ ! -e "$path" ] && [ ! -L "$path" ] || { 
        case "$path" in
            private/installation/installed.json) installed=1 ;;
            *) blocked=1 ;;
        esac
    }
done
if [ -e private/installation/migration.json ] || [ -L private/installation/migration.json ]; then
    [ "${installed:-0}" -eq 1 ] || blocked=1
fi
for path in .env private/resources/project-resources.json private/resources/configuration.json; do
    safe_file "$path"
done
if [ -e .env ] || [ -e private/resources/project-resources.json ] || [ -e private/resources/configuration.json ]; then
    configured=1
fi
if { [ -e private/resources/configuration.lock ] || [ -L private/resources/configuration.lock ]; } &&
    { [ ! -f .env ] || [ ! -f private/resources/configuration.json ]; }; then
    blocked=1
fi

if [ -e "$state" ]; then
    read -r marker old_dev old_ino old_uid old_gid old_mode < "$state"
    [ "$marker" = 'peanut.install-root-permissions.v1' ] || { echo 'invalid permission state' >&2; exit 1; }
    read -r now_dev now_ino now_uid now_gid now_mode <<EOF
$(stat_dir .)
EOF
    [ "$old_dev" = "$now_dev" ] && [ "$old_ino" = "$now_ino" ] && \
        [ "$old_uid" = "$now_uid" ] &&
        { [ "$now_gid" = "$app_gid" ] || [ "$now_gid" = "$old_gid" ]; } &&
        { [ "$now_mode" = 1775 ] || [ "$now_mode" = "$old_mode" ]; } || {
        echo 'permission window has unexpected root metadata' >&2; exit 1;
    }
    if [ "${configured:-0}" -eq 1 ] || [ "${installed:-0}" -eq 1 ] || [ "${blocked:-0}" -eq 1 ]; then
        chgrp "$old_gid" .
        chmod "$old_mode" .
        sync -f .
        rm "$state"
        sync -f docker/secrets
    else
        chgrp "$app_gid" .
        chmod 1775 .
        sync -f .
    fi
fi

if [ "${installed:-0}" -eq 1 ]; then
    [ "${blocked:-0}" -ne 1 ] &&
        [ -f private/installation/installed.json ] && [ ! -L private/installation/installed.json ] &&
        [ -f .env ] || {
        echo 'installed instance is incomplete' >&2; exit 1;
    }
    if grep -Eq '^PEANUT_INSTALLATION_MODE=guided$' .env && [ ! -f private/resources/configuration.json ]; then
        echo 'guided installation configuration receipt is missing' >&2; exit 1
    fi
elif [ "${blocked:-0}" -eq 1 ] || {
    [ "${configured:-0}" -eq 1 ] &&
    { [ ! -f .env ] || [ ! -f private/resources/configuration.json ]; }; }; then
    echo 'partial or legacy installation state blocks permission preparation' >&2
    exit 1
fi

# Change only named directories. Never traverse storage, uploads, or code.
for path in private runtime public private/storage private/installation private/resources private/resources/pending \
    runtime/upgrade public/storage runtime/cache runtime/log runtime/session runtime/temp runtime/storage runtime/generator runtime/file; do
    safe_dir "$path"
    owner=$(stat -c %u "$path")
    [ "$owner" -eq 0 ] || [ "$owner" -eq "$root_uid" ] || [ "$owner" -eq "$app_uid" ] || {
        echo "unknown directory owner: $path" >&2; exit 1;
    }
done
# Sticky runtime protection requires both the parent and update guard to remain
# owned by the deployment owner, never by the application process.
chown "$root_uid" private runtime runtime/upgrade
chmod 0755 runtime/upgrade
for path in private private/storage private/installation private/resources private/resources/pending runtime public/storage runtime/cache runtime/log runtime/session runtime/temp runtime/storage runtime/generator runtime/file; do
    chgrp "$app_gid" "$path"
    case "$path" in
        private) chmod 0750 "$path" ;;
        runtime) chmod 1775 "$path" ;;
        public/storage) chmod 0775 "$path" ;;
        *) chmod 0770 "$path" ;;
    esac
done

if [ -e private/collaboration ] || [ -L private/collaboration ]; then
    safe_dir private/collaboration
    owner=$(stat -c %u private/collaboration)
    [ "$owner" -eq 0 ] || [ "$owner" -eq "$root_uid" ] || { echo 'unknown collaboration directory owner' >&2; exit 1; }
    chown "0:$app_gid" private/collaboration
    chmod 0710 private/collaboration
fi
if [ -e private/collaboration/service ] || [ -L private/collaboration/service ]; then
    safe_dir private/collaboration/service
    owner=$(stat -c %u private/collaboration/service)
    [ "$owner" -eq 0 ] || [ "$owner" -eq "$root_uid" ] || { echo 'unknown collaboration service directory owner' >&2; exit 1; }
    for path in private/collaboration/service/* private/collaboration/service/.[!.]* private/collaboration/service/..?*; do
        [ -e "$path" ] || [ -L "$path" ] || continue
        case "$path" in
            private/collaboration/service/service-secret|private/collaboration/service/ca.pem) ;;
            *) echo 'unexpected collaboration service directory contents' >&2; exit 1 ;;
        esac
    done
    chown "0:$app_gid" private/collaboration/service
    chmod 0710 private/collaboration/service
fi
if [ -e private/collaboration/service/ca.pem ] || [ -L private/collaboration/service/ca.pem ]; then
    path=private/collaboration/service/ca.pem
    [ -f "$path" ] && [ ! -L "$path" ] && [ "$(stat -c %h "$path")" = 1 ] || { echo 'unsafe collaboration public CA' >&2; exit 1; }
    owner=$(stat -c %u "$path"); mode=$(stat -c %a "$path")
    [ "$owner" -eq 0 ] || [ "$owner" -eq "$root_uid" ] || [ "$owner" -eq "$app_uid" ] || { echo 'unknown collaboration public CA owner' >&2; exit 1; }
    [ $((0$mode & 0022)) -eq 0 ] || { echo 'writable collaboration public CA' >&2; exit 1; }
    chown "$app_uid:$app_gid" "$path"
    chmod 0644 "$path"
fi
for path in .env .env.bootstrap .env.installing \
    private/collaboration/service/service-secret private/collaboration/ticket-secret \
    private/resources/project-resources.json private/resources/configuration.json \
    private/resources/configuration.lock private/resources/database-provisioned.json \
    private/installation/installed.json private/installation/executing.json \
    private/installation/execution.lock private/installation/baseline.json \
    private/installation/migration.json; do
    safe_file "$path"
    if [ -f "$path" ]; then
        chown "$app_uid:$app_gid" "$path"
        chmod 0600 "$path"
    fi
done

if [ "${configured:-0}" -ne 1 ] && [ "${installed:-0}" -ne 1 ]; then
    [ -f docker/secrets/install-token ] && [ ! -L docker/secrets/install-token ] || {
        echo 'fresh installation token is unavailable' >&2; exit 1;
    }
    if [ "${1:-}" != --readonly-http ]; then
    if [ ! -e "$state" ]; then
        read -r old_dev old_ino old_uid old_gid old_mode <<EOF
$(stat_dir .)
EOF
        [ $((0$old_mode & 0022)) -eq 0 ] || { echo 'server root is already writable by non-owner' >&2; exit 1; }
        temporary="$state.tmp-$$"
        ( umask 077; printf 'peanut.install-root-permissions.v1 %s %s %s %s %s\n' \
            "$old_dev" "$old_ino" "$old_uid" "$old_gid" "$old_mode" > "$temporary" )
        sync -f "$temporary"
        mv "$temporary" "$state"
        sync -f docker/secrets
        chgrp "$app_gid" .
        chmod 1775 .
        sync -f .
    fi
    fi
fi
