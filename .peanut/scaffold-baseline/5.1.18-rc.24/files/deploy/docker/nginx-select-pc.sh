#!/bin/sh
set -eu

case "${PEANUT_PC_RENDER_MODE:-spa}" in
    spa)
        test -f /var/www/peanut-admin/server/public/pc/index.html || {
            printf 'nginx-select-pc: static PC entry is unavailable\n' >&2
            exit 1
        }
        ;;
    hybrid)
        getent hosts pc >/dev/null || {
            printf 'nginx-select-pc: hybrid mode requires the ssr Compose profile\n' >&2
            exit 1
        }
        cp /etc/nginx/peanut-admin-ssr.conf /etc/nginx/conf.d/default.conf
        ;;
    *)
        printf 'nginx-select-pc: expected spa or hybrid\n' >&2
        exit 1
        ;;
esac
