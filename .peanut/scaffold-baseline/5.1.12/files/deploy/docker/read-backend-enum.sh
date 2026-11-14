#!/bin/sh
# 仅提取两个启动枚举；绝不 source/eval 配置，也不输出其他配置值。
set -eu
[ "$#" -eq 2 ] || { printf 'backend-enum: invalid arguments\n' >&2; exit 2; }
source_file=$1
key=$2
[ -f "$source_file" ] && [ ! -L "$source_file" ] || {
    printf 'backend-enum: source is unavailable\n' >&2
    exit 2
}
case "$key" in
    DEPLOYMENT_MODE) allowed='standalone|multi-tenant' ;;
    PEANUT_INSTALLATION_MODE) allowed='automatic|guided' ;;
    *) printf 'backend-enum: unsupported key\n' >&2; exit 2 ;;
esac
awk -v key="$key" -v allowed="$allowed" '
function trim(value) {
    sub(/^[[:space:]]+/, "", value)
    sub(/[[:space:]]+$/, "", value)
    return value
}
{
    line = $0
    sub(/\r$/, "", line)
    if (line ~ /^[[:space:]]*([#;]|$)/) next
    separator = index(line, "=")
    if (!separator || trim(substr(line, 1, separator - 1)) != key) next
    count++
    value = substr(line, separator + 1)
    sub(/[;#].*$/, "", value)
    value = trim(value)
    quote = substr(value, 1, 1)
    if ((quote == "\"" || quote == sprintf("%c", 39)) && substr(value, length(value), 1) == quote) {
        value = substr(value, 2, length(value) - 2)
    }
    if (value !~ ("^(" allowed ")$")) invalid = 1
    selected = value
}
END {
    if (count != 1 || invalid) exit 2
    print selected
}' "$source_file"
