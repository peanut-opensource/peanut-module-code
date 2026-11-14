#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
if [ -x "$SCRIPT_DIR/update.sh" ]; then
    exec "$SCRIPT_DIR/update.sh" recover "$@"
fi
cat >&2 <<'EOF'
The original server-update recovery tool is absent. For an interrupted server-update
transaction, use the matching verified original release and its original update.sh
recover --workspace=<absolute-original-workspace> with its paired backup and journal.
For a Git product update, reconcile the existing .peanut/deploy-update.pending first,
then resume scripts/deploy-update.sh with the same target, branch and Compose project.
EOF
exit 1
