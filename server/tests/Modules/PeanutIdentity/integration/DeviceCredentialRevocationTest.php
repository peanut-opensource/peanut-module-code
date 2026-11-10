<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Contract\Dto\RevokeDeviceCredentialInput;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialRevocationEvidence;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialRevocationInput;
use think\facade\Db;

require __DIR__ . '/../support/DeviceCredentialRevocationFixture.php';

$enrollmentInput = deviceSigned($actor, 20001);
$enrollment = $enroll($enrollmentInput);
$key = $enrollment['resource_key'];
$input = $revokeInput(20002);
$first = $revoke($key, $input);
$check(
    $first['outcome'] === 'accepted' && $first['resource_revision'] === '2'
    && $first['data']['revocation_epoch'] === '1' && $first['data']['installation_key'] === null,
    'A real enrolled credential is revoked once with an exact version and epoch.',
);
$row = Db::table('pa_device_credential')->where('credential_key', $key)->find();
$basis = json_decode($row['revoke_basis_json'], true, flags: JSON_THROW_ON_ERROR);
$check(count($basis) === 20 && $basis['credential_status_before'] === 'enrolled'
    && $basis['credential_revision_before'] === '1' && $basis['reason_note'] === $input->reasonNote
    && DeviceCredentialRevocationEvidence::original($row) === $first, 'Twenty source fields reconstruct the exact first response.');
$expectedHash = hash('sha256', json_encode([
    'schema_version' => 1, 'operation' => 'peanut.identity.device-credential.revoke',
    'tenant_id' => '1', 'account_id' => '1', 'member_id' => '1', 'actor_client_key' => 'admin-web',
    'credential_key' => $key, 'command_key' => $input->commandKey,
    'expected_revision' => '1', 'reason_note' => $input->reasonNote,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$check($row['revoke_input_hash'] === $expectedHash, 'Canonical identity and every original field bind the domain command.');
$check(
    Db::table('business_test_audit')->where('event_type', 'identity.device-credential.revoked')->count() === 1
    && Db::table('pa_tenant_idempotency_record')->where('operation_key', 'peanut.identity.device-credential.revoke')->count() === 1,
    'One real audit and one real Core completion accompany revocation.',
);
$before = $state();
$check($revoke($key, $input) === $first && $state() === $before, 'An ordinary retry restores the whole permanent response.');
Db::table('pa_tenant_idempotency_record')->where('operation_key', 'peanut.identity.device-credential.revoke')->delete();
Db::table('pa_device_credential')->where('credential_key', $key)->update(['revision' => '9']);
$before = $state();
$check($revoke($key, $input) === $first && $state() === $before, 'Core removal and a later source version preserve the first revocation.');
$check($enroll($enrollmentInput) === $enrollment && $state() === $before, 'Revocation preserves the actual enrollment proof and first result.');
businessReject(static fn() => $revoke($key, $revokeInput(20003)), 'IDENTITY_DEVICE_ALREADY_REVOKED');
$check($state() === $before, 'A new command cannot overwrite an existing permanent revocation.');

foreach ([
    new RevokeDeviceCredentialInput('另一个原因', $input->commandKey, '1'),
    new RevokeDeviceCredentialInput($input->reasonNote, $input->commandKey, '2'),
] as $changed) {
    businessReject(static fn() => $revoke($key, $changed), 'IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH');
    $check($state() === $before, 'The same key rejects a different reason or original revision.');
}
$second = businessActor(1, 2);
businessReject(static fn() => $revoke($key, $input, $second), 'IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH');
$check($state() === $before, 'Another actual member cannot retrieve the original command.');
$otherAccount = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'fixture', 1, 91, 1, 'admin-web', new DateTimeImmutable(), 1, 1), 'synthetic-account-boundary');
businessReject(static fn() => $revoke($key, $input, $otherAccount), 'IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH');
$check($state() === $before, 'The trusted boundary input explicitly binds account identity as well as membership.');
$otherTenant = businessActor(2, 20);
businessReject(static fn() => $revoke($key, $input, $otherTenant), 'IDENTITY_DEVICE_NOT_FOUND');
businessReject(static fn() => $revoke(businessKey(29999), $input, $otherTenant), 'IDENTITY_DEVICE_NOT_FOUND');
$check($state() === $before, 'Unknown and other-tenant credential keys have the same scoped rejection.');
$permissions = $roles->grants;
$roles->grants = array_values(array_diff($permissions, ['peanut.identity.device-credential.revoke']));
businessReject(static fn() => $revoke($key, $input), 'IDENTITY_DEVICE_SCOPE_DENIED');
$roles->grants = $permissions;
$check($revoke($key, $input) === $first && $state() === $before, 'Current independent authority is checked before permanent recovery.');
$modules->available = false;
try {
    $revoke($key, $input);
    throw new LogicException('Expected the actual module gate.');
} catch (ModuleException) {
    $check($state() === $before, 'Deployment unavailability cannot expose a historical response.');
} finally {
    $modules->available = true;
}
$foreignClient = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'fixture', 1, 1, 1, 'another-client', new DateTimeImmutable(), 1, 1), 'synthetic-client-boundary');
businessReject(static fn() => $revoke($key, $input, $foreignClient), 'IDENTITY_DEVICE_SCOPE_DENIED');
businessReject(static fn() => $revokeService->revoke($actor, $key, $input), 'IDENTITY_DEVICE_SCOPE_DENIED');
$check($state() === $before && $contexts->isEmpty(), 'A foreign client or absent trusted execution scope performs no action.');
$lookalike = businessActor();
businessReject(static fn() => $contexts->run(
    new AdminExecutionContext($actor, 'http.admin.device.revoke', ['id' => 1, 'tenant_id' => 1, 'account_id' => 1]),
    static fn() => $revokeService->revoke($lookalike, $key, $input),
), 'IDENTITY_DEVICE_SCOPE_DENIED');
$check($state() === $before && $contexts->isEmpty(), 'A lookalike context with identical fields cannot replace the actual trusted actor object.');


$boundInput = deviceSigned($actor, 20010);
$bound = $enroll($boundInput);
$installationKey = businessKey(20011);
$asActor(static fn(TenantContext $trusted) => Db::transaction(
    static fn() => $bindings->bindInstallation($trusted, $bound['resource_key'], $installationKey, 'dcs-pos', $boundInput->deviceFingerprintHash),
));
$boundResult = $revoke($bound['resource_key'], $revokeInput(20012, '2'));
$check(
    $boundResult['resource_revision'] === '3' && $boundResult['data']['installation_key'] === $installationKey,
    'A real public binding remains attached to the revoked credential.',
);
businessReject(static fn() => $asActor(static fn(TenantContext $trusted) => Db::transaction(
    static fn() => $bindings->bindInstallation($trusted, $bound['resource_key'], $installationKey, 'dcs-pos', $boundInput->deviceFingerprintHash),
)), 'IDENTITY_DEVICE_UNAVAILABLE');
$check($enroll($boundInput) === $bound, 'A revoked real binding cannot be reused, while enrollment history remains intact.');

$cas = $enroll(deviceSigned($actor, 20020));
$before = $state();
businessReject(static fn() => $revoke($cas['resource_key'], $revokeInput(20021, '2')), 'IDENTITY_DEVICE_REVISION_CONFLICT');
$check($state() === $before, 'A stale or invented current revision has no persistent effect.');
Db::table('pa_device_credential')->where('credential_key', $cas['resource_key'])->update(['revision' => DeviceCredentialRevocationInput::UINT64_MAX]);
$before = $state();
businessReject(static fn() => $revoke($cas['resource_key'], $revokeInput(20021, DeviceCredentialRevocationInput::UINT64_MAX)), 'IDENTITY_DEVICE_INVARIANT');
$check($state() === $before, 'The native command rejects overflow before changing any state.');
Db::table('pa_device_credential')->where('credential_key', $cas['resource_key'])->update(['revision' => '18446744073709551614']);
$maximum = $revoke($cas['resource_key'], $revokeInput(20021, '18446744073709551614'));
$check($maximum['resource_revision'] === '18446744073709551615', 'The last valid uint64 increment stays exact.');
$maximumRow = Db::table('pa_device_credential')->where('credential_key', $cas['resource_key'])->find();
$broken = $maximum;
$broken['data']['changed_refs'][0]['revision'] = '18446744073709551614';
businessReject(static fn() => DeviceCredentialRevocationEvidence::original([
    ...$maximumRow, 'revoke_source_result_json' => json_encode($broken, JSON_THROW_ON_ERROR),
]), 'IDENTITY_DEVICE_INVARIANT');
$check(true, 'A neighboring uint64 string in a nested reference cannot pass through loose numeric comparison.');

$legacy = $enroll(deviceSigned($actor, 20030));
Db::table('pa_device_credential')->where('credential_key', $legacy['resource_key'])->update([
    'status' => 'revoked', 'revision' => '2', 'revocation_epoch' => '1',
    'revoked_at' => '2026-11-07 00:00:00.000', 'revoked_by_member_id' => 1, 'revoke_reason' => 'Explicit legacy fixture',
]);
$before = $state();
$unknown = $revoke($legacy['resource_key'], $revokeInput(20031, '2'));
$check(
    $unknown['outcome'] === 'pending' && $unknown['data'] === null && $state() === $before,
    'An explicit legacy revocation without a permanent source stays unresolved.',
);

foreach (['revoke_basis_json', 'revoke_source_result_json'] as $column) {
    $original = json_decode($row[$column], true, flags: JSON_THROW_ON_ERROR);
    foreach (array_keys($original) as $field) {
        $broken = $original;
        unset($broken[$field]);
        businessReject(static fn() => DeviceCredentialRevocationEvidence::original([
            ...$row, $column => json_encode($broken, JSON_THROW_ON_ERROR),
        ]), 'IDENTITY_DEVICE_INVARIANT');
        $check(true, 'Missing permanent ' . $field . ' is rejected.');
    }
}
$permutedBasis = array_reverse(json_decode($row['revoke_basis_json'], true, flags: JSON_THROW_ON_ERROR), true);
$permutedBody = array_reverse($first, true);
$permutedBody['data'] = array_reverse($first['data'], true);
$permutedBody['data']['changed_refs'][0] = array_reverse($first['data']['changed_refs'][0], true);
$check(DeviceCredentialRevocationEvidence::original([
    ...$row, 'revoke_basis_json' => json_encode($permutedBasis, JSON_THROW_ON_ERROR),
    'revoke_source_result_json' => json_encode($permutedBody, JSON_THROW_ON_ERROR),
]) === $permutedBody, 'Legal JSON object key ordering preserves the original typed response.');

$live = Db::table('pa_device_credential')->where('credential_key', $key)->find();
Db::table('pa_device_credential')->where('credential_key', $key)->update(['revoke_basis_json' => '{"schema_version":2}']);
$before = $state();
businessReject(static fn() => $revoke($key, $input), 'IDENTITY_DEVICE_INVARIANT');
$check($state() === $before, 'Persisted corrupt evidence cannot trigger another revocation or Core completion.');
Db::table('pa_device_credential')->where('credential_key', $key)->update(['revoke_basis_json' => $live['revoke_basis_json']]);
$check($contexts->isEmpty(), 'Actual trusted context is restored after every path.');
echo json_encode(['suite' => 'DeviceCredentialRevocation', 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
