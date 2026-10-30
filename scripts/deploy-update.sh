#!/usr/bin/env bash
# Update an installed product in its existing Git checkout.
set -Eeuo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
cd "$ROOT"
fail() { printf 'deploy-update: %s\n' "$*" >&2; exit 1; }

main() {
  target=''
  clients=''
  compose_project='-'
  prepare_release=false
  version=''
  branch=main
  for argument in "$@"; do
    case "$argument" in
      --target=*) target="${argument#*=}" ;;
      --compose-project=*) compose_project="${argument#*=}" ;;
      --prepare-release) prepare_release=true ;;
      --version=*) version="${argument#*=}" ;;
      --branch=*) branch="${argument#*=}" ;;
      *) fail 'usage: scripts/deploy-update.sh --prepare-release [--version=<app-version>] | --target=<commit> [--branch=<branch>] [--compose-project=<project>]' ;;
    esac
  done
  [[ -z "$target" || "$target" =~ ^[a-f0-9]{40}$ ]] || fail 'target must be a full commit hash'
  [[ "$compose_project" == - || "$compose_project" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]] || fail 'invalid Compose project'
  git check-ref-format "refs/heads/$branch" >/dev/null || fail 'invalid product branch'
  edition="$(python3 -c 'import json; print(json.load(open(".peanut/application-manifest.json"))["application"]["edition"])')"
  case "$edition" in standalone) clients=admin ;; multi-tenant) clients=admin,platform ;; *) fail 'invalid application Edition' ;; esac
  [[ "$(git rev-parse --show-toplevel)" == "$ROOT" ]] || fail 'run from the product Git checkout'
  if $prepare_release; then
    [[ -z "$target" && "$compose_project" == - && "$branch" == main ]] || fail 'release preparation runs on the development/release machine'
    declared_version="$(python3 -c 'import json; print(json.load(open(".peanut/application-manifest.json"))["application"]["version"])')"
    [[ -z "$version" || "$version" == "$declared_version" ]] || fail 'version differs from the product declaration'
    # Raw archives are build inputs. PHP runtime identity readers do not consume them.
    php -d zend.exception_ignore_args=1 <<'PHP'
<?php
require 'server/vendor/autoload.php';
app\common\value\installation\ApplicationSourceIdentity::loadGeneratedApp(getcwd() . '/server');
$versions = app\common\value\installation\ApplicationReleaseVersions::load('release-versions.json')->toArray();
app\common\infrastructure\scaffold\ReleaseDependencyIdentity::verifyArchives(
    getcwd(), $versions['core_web'], 'APPLICATION_RELEASE_CORE_WEB_ARCHIVE_INVALID',
);
(new app\platform\infrastructure\plugin\PluginLockResolver(getcwd() . '/server', '../plugins.lock'))->all();
PHP
    python3 - "$ROOT/server" <<'PY'
import importlib.util, pathlib, sys
sys.dont_write_bytecode = True
server = pathlib.Path(sys.argv[1])
spec = importlib.util.spec_from_file_location('native_vendor', server / 'docker/scripts/vendor-state.py')
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
module.check_lock(server)
PY
    static_target admin
    if [[ "$clients" == admin,platform ]]; then
      static_target platform
    fi
    NODE_ENV=production VITE_API_BASE_URL='' php -d zend.exception_ignore_args=1 <<'PHP'
<?php
require 'server/vendor/autoload.php';
require_once 'server/database/install.php';
$application = ensureThinkPhpApplication(getcwd() . '/server');
$clients = $application->config->get('deployment.mode') === 'multi-tenant' ? ['web', 'platform'] : ['web'];
$result = (new app\common\module\Dependencies(getcwd() . '/server'))->prepareRelease(
    new app\platform\infrastructure\plugin\PluginLockResolver(getcwd() . '/server', '../plugins.lock'),
    $clients,
);
foreach ($result['operations'] as $operation) {
    echo $operation['output'];
}
foreach ($result['frontend_adoption'] as $operation) {
    echo $operation['output'];
}
PHP
    printf '%s\n' '{"operation":"compile","arguments":[]}' | php -d zend.exception_ignore_args=1 server/module
    printf '[deploy-update] Prepared %s %s static files; review and commit server/public/admin' "$declared_version" "$edition"
    [[ "$clients" != admin,platform ]] || printf ' and server/public/platform'
    printf ' with the product source before main/tag review.\n'
    return
  fi
  [[ -z "$version" ]] || fail '--version belongs to --prepare-release'
  [[ "$(git branch --show-current)" == "$branch" ]] || fail 'the product checkout must be on the selected branch'
  [[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail 'tracked files have uncommitted changes'

  state="$ROOT/.peanut/deploy-update.pending"
  [[ ! -L "$ROOT/.peanut" && ! -L "$state" ]] || fail 'update state must not be a symlink'
  current_head="$(git rev-parse HEAD)"
  if [[ -e "$state" ]]; then
    [[ -f "$state" ]] || fail 'update state is not a regular file'
    read -r old_head new_head saved_edition saved_clients saved_project saved_branch extra < "$state"
    # Earlier pending records could only originate from the fixed main branch.
    saved_branch="${saved_branch:-main}"
    [[ "$old_head" =~ ^[a-f0-9]{40}$ && "$new_head" =~ ^[a-f0-9]{40}$ && -z "$extra" ]] || fail 'invalid pending update state'
    [[ "$saved_edition" == "$edition" && "$saved_clients" == "$clients" && "$saved_project" == "$compose_project" && "$saved_branch" == "$branch" ]] || fail 'resume with the recorded Edition, clients, Compose project and branch'
    [[ "$current_head" == "$old_head" || "$current_head" == "$new_head" ]] || fail 'checkout differs from the pending update'
    [[ -z "$target" || "$target" == "$new_head" ]] || fail 'finish the recorded pending update before selecting another target'
    printf '[deploy-update] Resuming %s -> %s\n' "$old_head" "$new_head"
  else
    old_head="$current_head"
    git fetch --quiet origin "refs/heads/$branch:refs/remotes/origin/$branch"
    new_head="$(git rev-parse "refs/remotes/origin/$branch^{commit}")"
    [[ -z "$target" || "$target" == "$new_head" ]] || fail 'selected origin branch changed from the reviewed target'
    if [[ "$old_head" == "$new_head" ]]; then
      printf '[deploy-update] Already at %s\n' "$new_head"
      exit 0
    fi
    git merge-base --is-ancestor "$old_head" "$new_head" || fail 'selected origin branch is not a fast-forward'
  fi
  changed="$(git diff --name-only "$old_head" "$new_head")"
  dependencies_changed=false
  if grep -Eq '^server/composer\.(json|lock)$' <<<"$changed"; then dependencies_changed=true; fi
  target_edition="$(git show "$new_head:.peanut/application-manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["application"]["edition"])')"
  [[ "$target_edition" == "$edition" ]] || fail 'an update cannot change the installed Edition'
  static_clients=(admin)
  if [[ "$edition" == multi-tenant ]]; then static_clients+=(platform); fi
  for client in "${static_clients[@]}"; do
    git cat-file -e "$new_head:server/public/$client/index.html" || fail 'target has no committed backend static entry; prepare the product release first'
  done

  compose=()
  if [[ "$compose_project" != - ]]; then
    compose_lines="$(python3 - "$ROOT/server" <<'PY'
import importlib.util, pathlib, sys
sys.dont_write_bytecode = True
server = pathlib.Path(sys.argv[1])
spec = importlib.util.spec_from_file_location('native_compose', server / 'docker/scripts/configure-collaboration.py')
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
print('\n'.join(module.compose_arguments(server)))
PY
  )"
    while IFS= read -r argument; do compose+=("$argument"); done <<< "$compose_lines"
    compose+=(-p "$compose_project")
    compose_run config --quiet
    # On a resumed update at the new HEAD, maintenance below verifies or repairs
    # the new lock. Before adoption, the old installed vendor must still verify.
    if [[ "$current_head" == "$old_head" ]] || ! $dependencies_changed; then
      python3 server/docker/scripts/vendor-state.py check --server "$ROOT/server"
    fi
  fi
  if [[ ! -e "$state" ]]; then
    (umask 077; set -o noclobber; printf '%s %s %s %s %s %s\n' "$old_head" "$new_head" "$edition" "$clients" "$compose_project" "$branch" > "$state")
  fi
  if [[ "$compose_project" != - ]]; then
    compose_run stop nginx php
    [[ -z "$(compose_run ps --status running -q nginx php)" ]] || fail 'instance writers are still running'
  fi
  git merge --ff-only "$new_head"
  if $dependencies_changed; then
    if [[ "$compose_project" == - ]]; then
      composer install --working-dir=server --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist
      php server/docker/scripts/prepare-vendor.php
    else
      php_image="$(sed -n 's/^PHP_IMAGE=//p' server/docker/.env | tail -n 1)"
      sh server/docker/scripts/prepare-vendor.sh maintenance "$ROOT/server" "$php_image"
    fi
  fi
  if [[ "$compose_project" == - ]]; then
    printf '%s\n' '{"operation":"compile","arguments":[]}' | php server/module
  else
    printf '%s\n' '{"operation":"compile","arguments":[]}' | compose_run run -T --rm --no-deps --entrypoint php php module
  fi
  if [[ "$compose_project" == - ]]; then
    printf '%s\n' '{"operation":"reconcile","arguments":["--release-locked","--prepared-release"]}' | php server/module
  else
    printf '%s\n' '{"operation":"reconcile","arguments":["--release-locked","--prepared-release"]}' | compose_run run -T --rm --no-deps --entrypoint php php module
  fi
  # Native discovery includes only database/migrations; fresh-only inputs live in database/installation.
  backend migrate:run
  backend clear --cache
  if [[ "$compose_project" != - ]]; then
    sh server/docker/scripts/start.sh -p "$compose_project"
  fi
  rm -- "$state"
  printf '[deploy-update] Updated %s -> %s\n' "$old_head" "$new_head"
}

compose_run() { (cd "$ROOT/server/docker"; "${compose[@]}" "$@"); }
backend() {
  if [[ "$compose_project" == - ]]; then php server/think "$@"
  else compose_run run -T --rm --no-deps --entrypoint php php think "$@"; fi
}
static_target() {
  local destination="server/public/$1"
  [[ ! -L server && ! -L server/public && ! -L "$destination" ]] || fail 'static destination must not be linked'
  [[ ! -e "$destination" || -d "$destination" ]] || fail 'static destination is not a directory'
  if git check-ignore --quiet --no-index -- "$destination/index.html"; then
    fail 'remove the backend static directory ignore rule before preparing a Git release'
  fi
}

# Parse the whole operation before Git updates this script's own tracked file.
main "$@"
