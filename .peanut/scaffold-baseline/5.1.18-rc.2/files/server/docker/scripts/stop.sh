#!/bin/sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
DOCKER_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)
[ -f "$DOCKER_DIR/.env" ] && [ ! -L "$DOCKER_DIR/.env" ] || { echo "docker .env is unavailable" >&2; exit 1; }
cd "$DOCKER_DIR"
python3 - "$DOCKER_DIR/.." "$@" <<'PY'
import importlib.util, pathlib, sys
sys.dont_write_bytecode=True
server=pathlib.Path(sys.argv[1]).resolve()
spec=importlib.util.spec_from_file_location('collaboration_owner',server/'docker/scripts/configure-collaboration.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
module.stop(server, sys.argv[2:])
PY
