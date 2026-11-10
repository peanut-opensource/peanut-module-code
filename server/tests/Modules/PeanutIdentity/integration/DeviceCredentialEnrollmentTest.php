<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Contract\Dto\EnrollDeviceCredentialInput;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialEvidence;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialProof;
use PeanutAdmin\Modules\Identity\Persistence\Model\DeviceCredential;
use think\facade\Db;

require __DIR__ . '/../support/DeviceCredentialFixture.php';

$checks = 0;
$check = static function (bool $result, string $message) use (&$checks): void {
    businessCheck($result, $message);
    $checks++;
};
$keyPair = sodium_crypto_sign_keypair();
$input = deviceSigned($actor, 1001, $keyPair);
$first = $enroll($input);
$check($first['outcome'] === 'accepted' && $first['resource_revision'] === '1'
    && $first['data']['status_code'] === 'enrolled', 'The actual credential producer commits enrolled R1.');
$row = Db::table('pa_device_credential')->where('credential_key', $first['resource_key'])->find();
$basis = json_decode($row['enroll_basis_json'], true, flags: JSON_THROW_ON_ERROR);
$message = DeviceCredentialProof::message('1', '1', '1', 'admin-web', $input);
$check($message === implode("\n", [
    'peanut.device-credential.enroll.v1', 'tenant_id=1', 'account_id=1', 'member_id=1', 'actor_client_key=admin-web',
    'client_key=dcs-pos', 'device_fingerprint_hash=' . $input->deviceFingerprintHash,
    'credential_public_key=' . $input->credentialPublicKey, 'command_key=' . $input->commandKey, '',
]), 'Nine fixed signed lines include the terminal LF.');
$check($row['enroll_input_hash'] === hash('sha256', $message . $input->credentialSignature)
    && $row['key_fingerprint'] === hash('sha256', sodium_crypto_sign_publickey($keyPair))
    && $basis['proof_hash'] === hash('sha256', hex2bin($input->credentialSignature))
    && count($basis) === 13, 'The persisted hashes bind actual proof bytes and canonical full input.');
$check(DeviceCredentialEvidence::original($row) === $first, 'The actual permanent proof reader verifies its original response.');
$check(
    Db::table('business_test_audit')->count() === 1 && Db::table('pa_tenant_idempotency_record')->count() === 1,
    'The actual Core receipt and transactional audit commit once.',
);
$before = $state();
$check($enroll($input) === $first && $state() === $before, 'An unchanged command returns the first complete response without new effects.');
$keys = array_keys($basis);
foreach ($keys as $field) {
    $bad = $basis;
    unset($bad[$field]);
    Db::table('pa_device_credential')->where('id', $row['id'])->update(['enroll_basis_json' => json_encode($bad)]);
    $before = $state();
    businessReject(static fn() => $enroll($input), 'IDENTITY_DEVICE_INVARIANT');
    $check($state() === $before, 'Each missing permanent provenance field fails closed.');
}
Db::table('pa_device_credential')->where('id', $row['id'])->update(['enroll_basis_json' => $row['enroll_basis_json']]);
foreach ([
    ['actor_account_id' => '2'], ['actor_member_id' => '2'], ['actor_client_key' => 'dcs-pos'],
    ['credential_key' => businessKey(20)], ['credential_revision' => '2'], ['credential_status' => 'bound'],
    ['proof_hash' => str_repeat('a', 64)], ['key_fingerprint' => str_repeat('b', 64)],
    ['device_fingerprint_hash' => str_repeat('c', 64)], ['client_key' => 'other-client'],
    ['enrolled_at' => '2000-01-01T00:00:00.000Z'], ['extra' => true],
] as $changes) {
    Db::table('pa_device_credential')->where('id', $row['id'])->update(['enroll_basis_json' => json_encode(array_replace($basis, $changes))]);
    businessReject(static fn() => $enroll($input), 'IDENTITY_DEVICE_INVARIANT');
    $checks++;
}
$reordered = $first;
$reordered['data']['changed_refs'][0] = array_reverse($first['data']['changed_refs'][0], true);
Db::table('pa_device_credential')->where('id', $row['id'])->update([
    'enroll_basis_json' => json_encode(array_reverse($basis, true)), 'enroll_source_result_json' => json_encode($reordered),
]);
$check($enroll($input) === $reordered, 'Native JSON object ordering preserves original values and output ordering.');
foreach ([
    ['module' => 'identity'], ['module' => 'other.identity'], ['revision' => 1], ['revision' => '2'], ['extra' => true],
] as $changes) {
    $bad = $first;
    $bad['data']['changed_refs'][0] = array_replace($first['data']['changed_refs'][0], $changes);
    Db::table('pa_device_credential')->where('id', $row['id'])->update(['enroll_source_result_json' => json_encode($bad)]);
    businessReject(static fn() => $enroll($input), 'IDENTITY_DEVICE_INVARIANT');
    $checks++;
}
Db::table('pa_device_credential')->where('id', $row['id'])->update([
    'enroll_basis_json' => $row['enroll_basis_json'], 'enroll_source_result_json' => $row['enroll_source_result_json'],
]);
foreach ([
    new EnrollDeviceCredentialInput('other-client', $input->deviceFingerprintHash, $input->credentialPublicKey, $input->credentialSignature, $input->commandKey),
    new EnrollDeviceCredentialInput($input->clientKey, str_repeat('a', 64), $input->credentialPublicKey, $input->credentialSignature, $input->commandKey),
    new EnrollDeviceCredentialInput($input->clientKey, $input->deviceFingerprintHash, str_repeat('b', 64), $input->credentialSignature, $input->commandKey),
    new EnrollDeviceCredentialInput($input->clientKey, $input->deviceFingerprintHash, $input->credentialPublicKey, str_repeat('c', 128), $input->commandKey),
] as $changed) {
    businessReject(static fn() => $enroll($changed), 'IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH');
    $checks++;
}
foreach ([
    businessActor(1, 2),
    TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'fixture', 1, 2, 1, 'admin-web', new DateTimeImmutable(), 1, 1), 'other-account'),
] as $changed) {
    businessReject(static fn() => $enroll($input, $changed), 'IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH');
    $checks++;
}
businessReject(static fn() => $service->enroll($actor, $input), 'IDENTITY_DEVICE_SCOPE_DENIED');
businessReject(static fn() => $contexts->run(
    new AdminExecutionContext($actor, 'wrong-actor-instance'),
    static fn() => $service->enroll(businessActor(), $input),
), 'IDENTITY_DEVICE_SCOPE_DENIED');
$wrongClient = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'fixture', 1, 1, 1, 'dcs-pos', new DateTimeImmutable(), 1, 1), 'wrong-client');
businessReject(static fn() => $enroll($input, $wrongClient), 'IDENTITY_DEVICE_SCOPE_DENIED');
$grants = $roles->grants;
$roles->grants = ['peanut.identity.device-credential.bind-installation'];
businessReject(static fn() => $enroll($input), 'IDENTITY_DEVICE_SCOPE_DENIED');
$roles->grants = $grants;
$modules->available = false;
try {
    $enroll($input);
    throw new RuntimeException('Expected protected deployment denial.');
} catch (ModuleException $error) {
    $check($error->errorCode === 'MODULE_NOT_AVAILABLE', 'Current deployment denial precedes permanent recovery.');
}
$modules->available = true;
$before = $state();
businessReject(static fn() => $enroll(deviceSigned($actor, 1002, $keyPair)), 'IDENTITY_DEVICE_KEY_REGISTERED');
$check($state() === $before, 'A new command cannot reuse a tenant public key, and its Core claim rolls back.');

$unsigned = deviceSigned($actor, 1003);
$before = $state();
foreach ([
    new EnrollDeviceCredentialInput('other-client', $unsigned->deviceFingerprintHash, $unsigned->credentialPublicKey, $unsigned->credentialSignature, $unsigned->commandKey),
    new EnrollDeviceCredentialInput($unsigned->clientKey, str_repeat('a', 64), $unsigned->credentialPublicKey, $unsigned->credentialSignature, $unsigned->commandKey),
    new EnrollDeviceCredentialInput($unsigned->clientKey, $unsigned->deviceFingerprintHash, str_repeat('b', 64), $unsigned->credentialSignature, $unsigned->commandKey),
    new EnrollDeviceCredentialInput($unsigned->clientKey, $unsigned->deviceFingerprintHash, $unsigned->credentialPublicKey, str_repeat('c', 128), $unsigned->commandKey),
    new EnrollDeviceCredentialInput($unsigned->clientKey, $unsigned->deviceFingerprintHash, $unsigned->credentialPublicKey, $unsigned->credentialSignature, businessKey(1004)),
] as $changed) {
    businessReject(static fn() => $enroll($changed), 'IDENTITY_DEVICE_PROOF_INVALID');
    $checks++;
}
businessReject(static fn() => $enroll($unsigned, businessActor(1, 2)), 'IDENTITY_DEVICE_PROOF_INVALID');
businessReject(static fn() => $enroll($unsigned, businessActor(2, 20)), 'IDENTITY_DEVICE_PROOF_INVALID');
$check($state() === $before, 'All actual signature substitutions preserve source, audit and Core tables.');
$lines = explode("\n", $message);
foreach (range(0, 8) as $line) {
    $tampered = $lines;
    $tampered[$line] .= 'x';
    $signature = bin2hex(sodium_crypto_sign_detached(implode("\n", $tampered), sodium_crypto_sign_secretkey($keyPair)));
    $source = $row;
    $source['credential_signature'] = $signature;
    $source['enroll_input_hash'] = hash('sha256', $message . $signature);
    $sourceBasis = $basis;
    $sourceBasis['proof_hash'] = hash('sha256', hex2bin($signature));
    $source['enroll_basis_json'] = json_encode($sourceBasis);
    businessReject(static fn() => DeviceCredentialEvidence::original($source), 'IDENTITY_DEVICE_INVARIANT');
    $checks++;
}
$other = businessActor(2, 20);
$separate = $enroll(deviceSigned($other, 1001, $keyPair), $other);
$check($separate['resource_key'] !== $first['resource_key'], 'Same public key and command remain separately scoped by a genuine other tenant.');

$bound = $asActor(static fn(TenantContext $trusted) => Db::transaction(
    static fn() => $bindings->bindInstallation($trusted, $first['resource_key'], businessKey(2001), $input->clientKey, $input->deviceFingerprintHash),
));
Db::table('pa_tenant_idempotency_record')->where('tenant_id', 1)->delete();
$before = $state();
$check($bound->revision === '2' && $enroll($input) === $first && $state() === $before, 'Later binding and Core cleanup preserve the first enrollment result.');
Db::table('pa_device_credential')->where('id', $row['id'])->update([
    'status' => 'revoked', 'revision' => '3', 'revocation_epoch' => '1',
    'revoked_at' => '2026-11-07 01:00:00.000', 'revoked_by_member_id' => 1, 'revoke_reason' => 'synthetic revocation',
]);
$before = $state();
$check($enroll($input) === $first && $state() === $before, 'A historical revoked credential still yields its original enrollment to the currently authorized actor.');
$check(false === DeviceCredential::onBeforeDelete(new DeviceCredential()), 'Deletion of credential history is rejected by its model.');
$check(!Db::connect()->getPdo()->inTransaction() && $contexts->isEmpty(), 'Enrollment restores the native execution and transaction boundaries.');
echo json_encode(['suite' => 'DeviceCredentialEnrollment', 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
