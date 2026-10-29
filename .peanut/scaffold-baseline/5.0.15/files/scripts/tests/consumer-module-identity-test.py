#!/usr/bin/env python3
"""Check the CR21 lifecycle helper cannot manufacture trusted identities."""
from __future__ import annotations

import importlib.machinery
import importlib.util
import json
import subprocess
import stat
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader(
    "consumer_module_reference_chain", str(ROOT / "scripts/consumer-module-reference-chain")
)
spec = importlib.util.spec_from_loader(loader.name, loader)
module = importlib.util.module_from_spec(spec)
loader.exec_module(module)


def write_private_env(path: Path) -> None:
    path.write_text(
        "DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_USER=fixture\nDB_PASS=fixture\n",
        encoding="utf-8",
    )
    path.chmod(0o600)


def parse_with(env_file: Path) -> object:
    previous = sys.argv[:]
    module.CONFIG_VALUES.clear()
    sys.argv = [
        "consumer-module-reference-chain",
        "--candidate",
        "a" * 40,
        "--lease",
        "fixture-lease",
        "--database",
        "fixture_consumer",
        "--database-resource",
        "fixture-mysql",
        "--endpoint",
        "fixture-host",
        "--author-database",
        "fixture_author",
        "--output",
        str(env_file.parent / "output"),
        "--env-file",
        str(env_file),
        "--installer-package",
        str(env_file.parent / "installer.tar"),
        "--installer-manifest",
        str(env_file.parent / "installer.json"),
        "--installer-sha256",
        "b" * 64,
        "--installer-manifest-sha256",
        "c" * 64,
    ]
    try:
        return module.parse_arguments()
    finally:
        sys.argv = previous
        module.CONFIG_VALUES.clear()


owned_tmp = ROOT / ".local/tmp/m3-identity-next-20260928"
owned_tmp.mkdir(parents=True, exist_ok=True)
with tempfile.TemporaryDirectory(dir=owned_tmp) as temporary:
    root = Path(temporary)
    env_file = root / "env"
    write_private_env(env_file)
    parsed = parse_with(env_file)
    assert not hasattr(parsed, "identity_context_file")

helper = module.lifecycle_helper()
for forbidden in [
    "PlatformContext::fromTrustedAutomation",
    "new PeanutAdmin\\Kernel\\Auth\\ValidatedTenantSession",
    "INSERT INTO pa_tenant(code,name,display_name,status",
]:
    assert forbidden not in helper, forbidden

for required in [
    "issue-identities",
    "PlatformOperatorSessionService::class",
    "PlatformOperatorIdentityPort::class",
    "TenantAuthService::class",
    "TenantOwnerInvitationAdminService::class",
    "TenantOwnerInvitationPublicService::class",
    "native_auth_services_after_consumer_install",
    "native_invitation_accept_login",
    "CR21_IDENTITY_SEED",
    "_FILE_INVALID",
    "CR21_IDENTITY_CONTEXT_OUTPUT_REQUIRED",
    "CR21_IDENTITY_CONTEXT_REQUIRED",
    "CR21_IDENTITY_CONTEXT_FILE_INVALID",
    "CR21_IDENTITY_CONTEXT_TOKEN_MISSING",
    "CR21_IDENTITY_CONTEXT_TENANT_MISMATCH",
    "'tenant_fixture' => 'native_provision_accept_activate'",
]:
    assert required in helper, required

source = (ROOT / "scripts/consumer-module-reference-chain").read_text(encoding="utf-8")
assert '--identity-context-file' not in source
assert "identity_context_file" in source and "written_0600" in source
assert '"member_authorization_entry": "native_non_root_member_grant_revoke_cross_tenant_denied"' in source
assert "native_non_root_member_grant_revoke" in helper
assert "native_non_root_member_cross_tenant_denied" in helper
assert "REFERENCE_CHAIN_PERMISSION_DENIED" in helper
assert "CR21_REFERENCE_MEMBER_NOT_ORDINARY" in helper
assert "CR21_REFERENCE_MEMBER_REVOKE_NOT_VISIBLE" in helper
assert "CR21_REFERENCE_MEMBER_B_CROSSTENANT_AUTHORIZATION_LEAK" in helper
assert "issued_identity = helper_action(" in source
assert "install_v1 = package_install(" in source
assert source.index("issued_identity = helper_action(") < source.index("install_v1 = package_install(")
assert "identity_seed_path.unlink(missing_ok=True)" in source
sanitized_status = helper.split("if ($action === 'issue-identities')", 1)[1].split("if ($action === 'provision-default')", 1)[0]
echo_status = sanitized_status.split("echo json_encode([", 1)[1].split("], JSON_THROW_ON_ERROR", 1)[0]
assert "platform_access_token" not in echo_status
assert "TENANT_B_OWNER_PASSWORD" not in echo_status

mode = stat.S_IMODE((ROOT / "scripts/consumer-module-reference-chain").stat().st_mode)
assert mode & stat.S_IXUSR, "consumer-module-reference-chain should remain executable"

# Validate the actual generated helper, not a duplicate file-policy implementation.
assert source.index("installed = json_output(command(") < source.index("issued_identity = helper_action(")
issue = helper.split("if ($action === 'issue-identities')", 1)[1].split("if ($action === 'provision-default')", 1)[0]
assert issue.index("->provision(") < issue.index("->accept(") < issue.index("->transitionTenant(") < issue.index("$tenantB = $tenantAuth->login(")
assert "TenantStatus::Active" in issue
assert "provisioned_accepted_and_activated" in issue
assert "identity_context.unlink(missing_ok=True)" in source.split("    finally:\n        # Retain diagnostics", 1)[1]
assert "identity_seed_path.unlink(missing_ok=True)" in source.split("    finally:\n        # Retain diagnostics", 1)[1]
assert "zend.exception_ignore_args" in helper
file_helpers = "$identityBundle =" + helper.split("$identityBundle =", 1)[1].split("$platformContext =", 1)[0]
with tempfile.TemporaryDirectory(dir=owned_tmp) as temporary:
    root = Path(temporary)
    generated = root / "generated-helper.php"
    generated.write_text(helper, encoding="utf-8")
    lint = subprocess.run([str(module.PHP), "-l", str(generated)], capture_output=True, text=True, timeout=30)
    assert lint.returncode == 0, lint.stderr
    probe = root / "native-private-files.php"
    probe.write_text(
        "<?php\ndeclare(strict_types=1);\numask(0077);\n"
        + "require " + json.dumps(str(ROOT / "server/vendor/autoload.php")) + ";\n"
        + "$root = $argv[1]; $identityFile = $root . '/identity.json';\n"
        + file_helpers
        + r"""
$checks = [];
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks[] = $label;
};
$reject = static function (callable $action, string $code) use ($check): void {
    try { $action(); }
    catch (RuntimeException $error) { $check($error->getMessage() === $code, $code); return; }
    throw new RuntimeException('expected rejection: ' . $code);
};
$seed = $root . '/seed.env';
$reject(fn() => $identityBundle(), 'CR21_IDENTITY_CONTEXT_FILE_INVALID');
$reject(fn() => $privateKeyValueFile($seed, 'CR21_IDENTITY_SEED'), 'CR21_IDENTITY_SEED_FILE_INVALID');
file_put_contents($seed, "ADMIN_INITIAL_EMAIL=fixture@example.test\nADMIN_INITIAL_PASSWORD=synthetic-only-secret\n");
chmod($seed, 0600);
$seedValues = $privateKeyValueFile($seed, 'CR21_IDENTITY_SEED');
$check($requiredSecret($seedValues, 'ADMIN_INITIAL_PASSWORD') === 'synthetic-only-secret', 'private seed read');
chmod($seed, 0644);
$reject(fn() => $privateKeyValueFile($seed, 'CR21_IDENTITY_SEED'), 'CR21_IDENTITY_SEED_FILE_INVALID');
chmod($seed, 0600);
$link = $root . '/seed-link.env'; symlink($seed, $link);
$reject(fn() => $privateKeyValueFile($link, 'CR21_IDENTITY_SEED'), 'CR21_IDENTITY_SEED_FILE_INVALID');
$reject(fn() => $requiredSecret([], 'ADMIN_INITIAL_PASSWORD'), 'CR21_SECRET_MISSING:ADMIN_INITIAL_PASSWORD');
$reject(fn() => $requiredToken(['platform_access_token' => '  '], 'platform_access_token'), 'CR21_IDENTITY_CONTEXT_TOKEN_MISSING:platform_access_token');
$reject(fn() => $tenantToken([], 'default'), 'CR21_IDENTITY_CONTEXT_TENANT_TOKENS_MISSING');
$bundle = ['platform_access_token' => 'pa_pat_synthetic-only', 'tenant_access_tokens' => ['default' => 'pa_tat_synthetic-only']];
$writeIdentityBundle($identityFile, $bundle);
clearstatcache(true, $identityFile);
$check((fileperms($identityFile) & 0777) === 0600, 'private token write');
$check($identityBundle() === $bundle, 'actual token round trip');
$check($tenantToken($identityBundle(), 'default') === 'pa_tat_synthetic-only', 'tenant token lookup');
chmod($identityFile, 0644);
$reject(fn() => $identityBundle(), 'CR21_IDENTITY_CONTEXT_FILE_INVALID');
chmod($identityFile, 0600);
$before = hash_file('sha256', $seed);
$reject(fn() => $writeIdentityBundle($link, $bundle), 'CR21_IDENTITY_CONTEXT_OUTPUT_INVALID');
$check(hash_file('sha256', $seed) === $before, 'rejected output leaves target unchanged');
file_put_contents($identityFile, 'null');
$reject(fn() => $identityBundle(), 'CR21_IDENTITY_CONTEXT_JSON_INVALID');
$check(glob($identityFile . '.tmp-*') === [], 'temporary token files cleaned');
$signatures = [
    [app\platform\services\PlatformOperatorSessionService::class, 'login', 5],
    [app\platform\identity\PlatformOperatorIdentityPort::class, 'requireActive', 2],
    [PeanutAdmin\Modules\Identity\Auth\TenantAuthService::class, 'login', 7],
    [PeanutAdmin\Modules\Identity\Auth\TenantAuthService::class, 'context', 2],
    [PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationAdminService::class, 'provision', 6],
    [PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationPublicService::class, 'accept', 2],
    [PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService::class, 'transitionTenant', 5],
];
foreach ($signatures as [$class, $method, $parameters]) {
    $reflection = new ReflectionMethod($class, $method);
    $check($reflection->isPublic() && $reflection->getNumberOfParameters() === $parameters, 'native signature ' . $class . '::' . $method);
}
echo json_encode(['status' => 'passed', 'checks' => $checks, 'scope' => 'generated private-file behavior and native API signatures; no authentication or database execution'], JSON_THROW_ON_ERROR), PHP_EOL;
""",
        encoding="utf-8",
    )
    native = subprocess.run([str(module.PHP), str(probe), str(root)], cwd=ROOT, capture_output=True, text=True, timeout=60)
    assert native.returncode == 0, native.stderr
    assert native.stderr == "", native.stderr
    assert "synthetic-only-secret" not in native.stdout and "pa_pat_synthetic-only" not in native.stdout
    result = json.loads(native.stdout)
    assert result["status"] == "passed"
    print(f"CONSUMER-IDENTITY-NATIVE-001 passed ({len(result['checks'])} checks)")

print("CONSUMER-IDENTITY-CONTRACT-001 passed (18 checks)")
