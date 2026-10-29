#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$ROOT"
mode="${1:-}"
shift || true
BACKEND_ENV=''
if [[ "${1:-}" == '--env-file' && $# -ge 2 ]]; then
  BACKEND_ENV="$2"
  shift 2
fi
[[ $# -eq 0 && "$BACKEND_ENV" == /* ]] || {
  echo 'ERROR: --env-file /absolute/path is required' >&2
  exit 2
}
[[ -f "$BACKEND_ENV" && ! -L "$BACKEND_ENV" ]] || {
  echo "ERROR: backend environment is missing or unsafe: $BACKEND_ENV" >&2
  exit 2
}
backend_directory=$(CDPATH= cd -- "$(dirname -- "$BACKEND_ENV")" && pwd -P)
[[ "$backend_directory" == "$ROOT/server" ]] || { echo 'backend environment must be inside server/' >&2; exit 2; }
backend_name=$(basename -- "$BACKEND_ENV")
case "$backend_name" in
  .env) native_env_name= ;;
  .env.example) echo 'backend environment must not be the example file' >&2; exit 2 ;;
  .env.*) native_env_name=${backend_name#.env.} ;;
  *) echo 'backend environment must be .env or .env.<name>' >&2; exit 2 ;;
esac
export PHP_ENV_NAME="$native_env_name"

case "$mode" in
  --daily|--broad|--integration|--mysql) ;;
  --fast|--full)
    echo 'ERROR: --fast/--full are retired; use --daily, --broad, or --integration' >&2
    exit 2
    ;;
  *)
    echo 'ERROR: ci-server-check.sh requires --daily, --broad, or --integration' >&2
    exit 2
    ;;
esac
[[ "$mode" != '--mysql' ]] || mode='--integration'

candidate="$(git rev-parse HEAD)"
tree="$(git rev-parse HEAD^{tree})"
started_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
receipt="$(mktemp "${TMPDIR:-/tmp}/peanut-ci-server-receipt.XXXXXX")"
changed_file="$(mktemp "${TMPDIR:-/tmp}/peanut-ci-server-changes.XXXXXX")"
changed_php_file="$(mktemp "${TMPDIR:-/tmp}/peanut-ci-server-php.XXXXXX")"
selected_file="$(mktemp "${TMPDIR:-/tmp}/peanut-ci-server-tests.XXXXXX")"
unit_discovery_file="$(mktemp "${TMPDIR:-/tmp}/peanut-ci-server-unit-discovery.XXXXXX")"
trap 'rm -f -- "$receipt" "$changed_file" "$changed_php_file" "$selected_file" "$unit_discovery_file"' EXIT
command_count=0
test_targets=0
unit_test_count=0

quote_command() {
  local rendered=''
  local item
  for item in "$@"; do
    printf -v item '%q' "$item"
    rendered="${rendered}${rendered:+ }${item}"
  done
  printf '%s' "$rendered"
}

write_summary() {
  local result="$1"
  local summary_file="${GITHUB_STEP_SUMMARY:-}"
  {
    printf '## Server gate %s\n\n' "$mode"
    printf -- '- Candidate: `%s`\n' "$candidate"
    printf -- '- Tree: `%s`\n' "$tree"
    printf -- '- Started: `%s`\n' "$started_at"
    printf -- '- Result: **%s**\n' "$result"
    printf -- '- Commands: %d\n' "$command_count"
    printf -- '- Test targets: %d\n' "$test_targets"
    printf -- '- Discovered Unit tests: %d\n\n' "$unit_test_count"
    printf '```text\n'
    sed -n '1,240p' "$receipt"
    printf '```\n'
  } | if [[ -n "$summary_file" ]]; then tee -a "$summary_file"; else cat; fi
}

run_command() {
  local label="$1"
  local tests="$2"
  shift 2
  local rendered
  local status
  rendered="$(quote_command "$@")"
  printf '[ci-command] %s command=%s\n' "$label" "$rendered"
  set +e
  "$@"
  status=$?
  set -e
  command_count=$((command_count + 1))
  test_targets=$((test_targets + tests))
  printf '%s\texit=%d\ttest_targets=%d\t%s\n' "$label" "$status" "$tests" "$rendered" >>"$receipt"
  if [[ "$status" -ne 0 ]]; then
    write_summary failed
    exit "$status"
  fi
}

select_test() {
  local path="$1"
  [[ -f "$path" && ! -L "$path" ]] || {
    printf 'ERROR: mapped test target is missing: %s\n' "$path" >&2
    exit 1
  }
  printf '%s\n' "$path" >>"$selected_file"
}

registered_mysql_test() {
  case "$1" in
    server/tests/Modules/Official/Integration/IntegrationSecurityMysqlTest.php|\
    server/tests/Modules/Official/Notification/mysql-harness.php|\
    server/tests/Multitenancy/MemberSessionTenantIsolationTest.php)
      return 0
      ;;
  esac
  return 1
}

direct_test_target() {
  case "$1" in
    server/tests/fixtures/*|server/tests/Support/*|server/tests/*/Support/*)
      return 1
      ;;
    server/tests/*Test.php|\
    server/tests/Modules/Official/*/feature-harness.php|\
    server/tests/Modules/Official/Task/worker-composition-harness.php|\
    server/tests/scripts/CheckModuleNamespaceMigration.php)
      return 0
      ;;
  esac
  return 1
}

run_command admin-api-permissions 0 php scripts/check-admin-api-permissions.php
run_command test-integrity 0 php scripts/check-test-integrity
run_command api-contract-source 0 php scripts/generate-api-contracts.php --check

if [[ "$mode" == '--integration' ]]; then
  mysql_resource_mode="$(printenv PEANUT_MYSQL_RESOURCE_MODE 2>/dev/null || true)"
  [[ -n "$mysql_resource_mode" ]] || mysql_resource_mode='--registered'
  [[ "$mysql_resource_mode" == '--registered' || "$mysql_resource_mode" == '--ci-service' ]] || {
    echo 'ERROR: PEANUT_MYSQL_RESOURCE_MODE is invalid' >&2
    exit 2
  }
  run_command registered-mysql-integration 3 \
    "$ROOT/scripts/tests/run-registered-mysql-tests" "$mysql_resource_mode" --env-file "$BACKEND_ENV"
  write_summary passed
  exit 0
fi

run_command api-generated-drift 0 "$ROOT/scripts/check-openapi"

if [[ "$mode" == '--daily' ]]; then
  base="${CI_BASE_REF:-}"
  head="${CI_HEAD_REF:-HEAD}"
  comparison="${CI_COMPARISON:-merge-base}"
  [[ "$comparison" == 'merge-base' || "$comparison" == 'direct' ]] \
    || { echo "ERROR: CI_COMPARISON is invalid: ${comparison}" >&2; exit 2; }
  [[ -n "$base" ]] || { echo 'ERROR: CI_BASE_REF is required for --daily' >&2; exit 2; }
  git rev-parse --verify "${base}^{commit}" >/dev/null 2>&1 \
    && git rev-parse --verify "${head}^{commit}" >/dev/null 2>&1 \
    || { echo "ERROR: daily comparison baseline is unavailable: ${base}...${head}" >&2; exit 1; }
  if [[ "$comparison" == 'direct' ]]; then
    git diff --name-only "$base" "$head" >"$changed_file"
  else
    git diff --name-only "${base}...${head}" >"$changed_file"
  fi
  [[ -s "$changed_file" ]] || { echo 'ERROR: daily comparison produced zero changed paths' >&2; exit 1; }
else
  git ls-files >"$changed_file"
fi

while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  if [[ "$path" == *.php && -f "$path" ]]; then
    printf '%s\n' "$path" >>"$changed_php_file"
  fi
done <"$changed_file"
if [[ "$mode" == '--broad' ]]; then
  find server/app server/config server/database server/route -type f -name '*.php' -print \
    | LC_ALL=C sort -u >"$changed_php_file"
fi

while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  run_command "php-lint:${path}" 0 php -l "$path"
done <"$changed_php_file"

# The stable, database-free Unit group runs as a whole on every server candidate.
unit_discovery_command=(
  php server/vendor/bin/phpunit
  --no-configuration
  --bootstrap server/tests/Support/CiBootstrap.php
  --list-tests
  server/tests/Unit
)
set +e
"${unit_discovery_command[@]}" >"$unit_discovery_file" 2>&1
unit_discovery_status=$?
set -e
command_count=$((command_count + 1))
unit_test_count="$(grep -c '^ - ' "$unit_discovery_file" || true)"
printf '%s\texit=%d\tdiscovered_tests=%d\t%s\n' \
  unit-test-discovery "$unit_discovery_status" "$unit_test_count" \
  "$(quote_command "${unit_discovery_command[@]}")" >>"$receipt"
cat "$unit_discovery_file"
if [[ "$unit_discovery_status" -ne 0 || "$unit_test_count" -eq 0 ]]; then
  echo 'ERROR: stable Unit group discovery failed or selected zero tests' >&2
  write_summary failed
  exit 1
fi
while IFS= read -r test_file; do
  select_test "$test_file"
done < <(find server/tests/Unit -maxdepth 1 -type f -name '*.php' -print | LC_ALL=C sort)

behavior_selected=0
while IFS= read -r path; do
  [[ -n "$path" ]] || continue
  if direct_test_target "$path" && ! registered_mysql_test "$path" && [[ -f "$path" ]]; then
    select_test "$path"
    behavior_selected=1
  fi
  case "$path" in
    server/app/api/metadata/*|server/app/modules/*/*/api/metadata/*|server/route/*|server/config/admin_api_access.php|scripts/generate-api-contracts.php|scripts/check-openapi)
      select_test server/tests/Unit/ApiContractCatalogTest.php
      select_test server/tests/Unit/ApiMetadataCompletionTest.php
      select_test server/tests/Unit/PublicApiArtifactCheckTest.php
      behavior_selected=1
      ;;
    server/database/*)
      select_test server/tests/Productization/FreshSchemaBaselineTest.php
      select_test server/tests/Multitenancy/NativeAdminIdentityRuntimeContractTest.php
      behavior_selected=1
      ;;
    server/app/modules/*|plugins/*|plugins.lock)
      select_test server/tests/Productization/ModuleCoreDependencyTest.php
      select_test server/tests/Productization/PluginArtifactContractTest.php
      select_test server/tests/Productization/PluginModuleContractTest.php
      select_test server/tests/Multitenancy/OfficialCapabilityTenantQualificationTest.php
      behavior_selected=1
      ;;
    server/app/platform/infrastructure/plugin/PluginPackageSourcePromoter.php)
      select_test server/tests/Productization/ModuleSourcePathIsolationTest.php
      select_test server/tests/Productization/ThinkPhpArchitectureBehaviorMatrixTest.php
      behavior_selected=1
      ;;
    server/app/*|server/config/*)
      select_test server/tests/Productization/ThinkPhpArchitectureBehaviorMatrixTest.php
      behavior_selected=1
      ;;
    scripts/run-php-test|scripts/ci-server-check.sh|scripts/check-test-integrity|.github/workflows/ci.yml)
      select_test server/tests/Unit/PhpTestRunnerTest.php
      behavior_selected=1
      ;;
    scripts/create-app|scripts/build-application-template-inventory|scaffold/*)
      select_test server/tests/Productization/CreateApplicationTest.php
      behavior_selected=1
      ;;
    release-versions.json|server/composer.json|server/composer.lock)
      select_test server/tests/Productization/ModuleCoreDependencyTest.php
      select_test server/tests/Unit/GeneratedPackageIdentityTest.php
      select_test server/tests/Productization/ThinkPhpArchitectureBehaviorMatrixTest.php
      behavior_selected=1
      ;;
  esac
done <"$changed_file"

if [[ "$mode" == '--broad' || "$behavior_selected" -eq 0 ]]; then
  # An unclassified server-affecting path expands to the stable broad behavior group.
  for test_file in \
    server/tests/Productization/ModuleCoreDependencyTest.php \
    server/tests/Productization/ModuleSourcePathIsolationTest.php \
    server/tests/Productization/FreshSchemaBaselineTest.php \
    server/tests/Productization/ThinkPhpArchitectureBehaviorMatrixTest.php \
    server/tests/Productization/OAuthChannelHostTest.php \
    server/tests/Multitenancy/NativeAdminIdentityRuntimeContractTest.php \
    server/tests/Multitenancy/OfficialCapabilityTenantQualificationTest.php \
    server/tests/Productization/MemberFinanceHostTest.php \
    server/tests/Productization/PluginArtifactContractTest.php \
    server/tests/Productization/PluginModuleContractTest.php \
    server/tests/Productization/OfficialArticleModuleContractTest.php \
    server/tests/Productization/PluginLifecycleMigrationContractTest.php; do
    select_test "$test_file"
  done
fi

LC_ALL=C sort -u "$selected_file" -o "$selected_file"
[[ -s "$selected_file" ]] || {
  echo 'ERROR: server classification selected zero test targets' >&2
  exit 1
}

while IFS= read -r test_file; do
  [[ -n "$test_file" ]] || continue
  run_command "test:${test_file}" 1 \
    php "$ROOT/scripts/run-php-test" "--env-file=$BACKEND_ENV" "$test_file"
done <"$selected_file"

write_summary passed
