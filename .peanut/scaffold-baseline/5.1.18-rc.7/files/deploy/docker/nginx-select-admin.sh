#!/bin/sh

set -eu

backend_source=/var/www/peanut-admin/server/.env.source
[ -f "$backend_source" ] && [ ! -L "$backend_source" ] || {
    printf 'nginx-select-admin: backend environment source is unavailable\n' >&2
    exit 1
}
DEPLOYMENT_MODE=$(/usr/local/bin/peanut-read-backend-enum "$backend_source" DEPLOYMENT_MODE)
case "$DEPLOYMENT_MODE" in
    multi-tenant) ;;
    *)
        printf 'nginx-select-admin: this artifact requires DEPLOYMENT_MODE=multi-tenant\n' >&2
        exit 1
        ;;
esac

source_dir="/opt/peanut-admin/admin/$DEPLOYMENT_MODE"
target="/var/www/peanut-admin/server/public/admin"

if [ ! -f "$source_dir/index.html" ]; then
    printf 'nginx-select-admin: selected admin bundle is unavailable: %s\n' "$DEPLOYMENT_MODE" >&2
    exit 1
fi
if [ -e "$target" ] && [ ! -L "$target" ]; then
    printf 'nginx-select-admin: admin target is not a symbolic link\n' >&2
    exit 1
fi

ln -sfn "$source_dir" "$target"
