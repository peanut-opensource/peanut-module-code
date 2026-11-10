<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\BaseQuery;
use think\facade\Db;

require __DIR__ . '/../support/DeviceCredentialFixture.php';

$checks = 0;
$check = static function (bool $result, string $message) use (&$checks): void {
    businessCheck($result, $message);
    $checks++;
};
$input = deviceSigned($actor, 1101);
$created = $enroll($input);
$key = $created['resource_key'];
$bind = static fn(string $installation = '00000000-0000-4000-8000-000000002101') =>
    $asActor(static fn(TenantContext $trusted) => $bindings->bindInstallation(
        $trusted,
        $key,
        $installation,
        $input->clientKey,
        $input->deviceFingerprintHash,
    ));
$before = $state();
businessReject($bind, 'IDENTITY_DEVICE_INVARIANT');
$check($state() === $before, 'An absent caller transaction rejects before writes.');
$connection = Db::connect();
$originalBuilder = $connection->getBuilder();
$builder = new class ($connection) extends \think\db\builder\Sqlite {
    public array $locks = [];
    protected function parseLock(BaseQuery $query, bool|string $lock = false): string
    {
        $this->locks[] = [$query->getOption('table'), $lock, Db::connect()->getPdo()->inTransaction()];
        return parent::parseLock($query, $lock);
    }
};
$property = new ReflectionProperty($connection, 'builder');
$property->setValue($connection, $builder);
$first = Db::transaction(static fn() => $bind());
$property->setValue($connection, $originalBuilder);
$check(in_array(['pa_device_credential', true, true], $builder->locks, true), 'Binding locks the real tenant credential in the caller transaction.');
$check(get_object_vars($first) === [
    'credentialKey' => $key, 'revision' => '2', 'clientKey' => $input->clientKey,
    'deviceFingerprintHash' => $input->deviceFingerprintHash,
    'keyFingerprint' => hash('sha256', hex2bin($input->credentialPublicKey)),
    'installationKey' => businessKey(2101), 'status' => 'bound', 'revocationEpoch' => '0',
    'boundAt' => $first->boundAt,
], 'The public binding DTO exposes exactly nine concrete immutable reference fields.');
$check(
    preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $first->boundAt) === 1,
    'Binding preserves the original UTC millisecond instant.',
);
$before = $state();
$check(
    get_object_vars(Db::transaction(static fn() => $bind())) === get_object_vars($first) && $state() === $before,
    'Same-installation internal reentry returns the fixed association without another audit.',
);
businessReject(static fn() => Db::transaction(static fn() => $bind(businessKey(2102))), 'IDENTITY_DEVICE_UNAVAILABLE');
$check($state() === $before, 'One credential cannot be rebound to a second installation.');
foreach ([
    [businessKey(2101), 'other-client', $input->deviceFingerprintHash, 'IDENTITY_DEVICE_BINDING_MISMATCH'],
    [businessKey(2101), $input->clientKey, str_repeat('f', 64), 'IDENTITY_DEVICE_BINDING_MISMATCH'],
    ['invalid', $input->clientKey, $input->deviceFingerprintHash, 'IDENTITY_DEVICE_INPUT_INVALID'],
    [businessKey(2101), 'INVALID', $input->deviceFingerprintHash, 'IDENTITY_DEVICE_INPUT_INVALID'],
    [businessKey(2101), $input->clientKey, 'invalid', 'IDENTITY_DEVICE_INPUT_INVALID'],
] as [$installation, $client, $fingerprint, $code]) {
    businessReject(static fn() => $asActor(static fn(TenantContext $trusted) => Db::transaction(
        static fn() => $bindings->bindInstallation($trusted, $key, $installation, $client, $fingerprint),
    )), $code);
    $checks++;
}
foreach ([$key, businessKey(9999), 'invalid'] as $foreign) {
    businessReject(static fn() => $asActor(
        static fn(TenantContext $trusted) => $bindings->assertRegistrationAccess($trusted, $foreign),
        businessActor(2, 20),
    ), 'IDENTITY_DEVICE_NOT_FOUND');
    $checks++;
}
businessReject(static fn() => $bindings->assertRegistrationAccess($actor, $key), 'IDENTITY_DEVICE_SCOPE_DENIED');
businessReject(static fn() => $contexts->run(
    new AdminExecutionContext($actor, 'copied-actor'),
    static fn() => $bindings->assertRegistrationAccess(businessActor(), $key),
), 'IDENTITY_DEVICE_SCOPE_DENIED');
$grants = $roles->grants;
$roles->grants = ['peanut.identity.device-credential.enroll'];
businessReject(static fn() => $asActor(static fn(TenantContext $trusted) => $bindings->assertRegistrationAccess($trusted, $key)), 'IDENTITY_DEVICE_SCOPE_DENIED');
businessReject(static fn() => Db::transaction(static fn() => $bind()), 'IDENTITY_DEVICE_SCOPE_DENIED');
$roles->grants = $grants;
$check($state() === $before, 'Current identity and separate binding permission are required for recovery and mutation.');

$freshInput = deviceSigned($actor, 1102);
$fresh = $enroll($freshInput);
$bindFresh = static fn() => $asActor(static fn(TenantContext $trusted) => $bindings->bindInstallation(
    $trusted,
    $fresh['resource_key'],
    businessKey(2103),
    $freshInput->clientKey,
    $freshInput->deviceFingerprintHash,
));
$before = $state();
try {
    Db::transaction(static function () use ($bindFresh): void {
        $bindFresh();
        throw new RuntimeException('injected consumer failure');
    });
    throw new LogicException('Expected the actual consumer failure.');
} catch (RuntimeException $error) {
    $check(
        $error->getMessage() === 'injected consumer failure' && $state() === $before,
        'A consumer failure rolls back actual credential binding and audit together.',
    );
}
$audit->fail = true;
try {
    Db::transaction($bindFresh);
    throw new LogicException('Expected audit failure.');
} catch (RuntimeException $error) {
    $check(
        $error->getMessage() === 'injected audit failure' && $state() === $before,
        'Audit persistence failure rolls back the binding inside the caller transaction.',
    );
}
$audit->fail = false;
$source = Db::table('pa_device_credential')->where('credential_key', $fresh['resource_key'])->find();
Db::table('pa_device_credential')->where('id', $source['id'])->update(['enroll_basis_json' => '{}']);
$before = $state();
businessReject(static fn() => Db::transaction($bindFresh), 'IDENTITY_DEVICE_INVARIANT');
$check($state() === $before, 'A caller cannot bind damaged permanent enrollment evidence.');
Db::table('pa_device_credential')->where('id', $source['id'])->update(['enroll_basis_json' => $source['enroll_basis_json']]);
$casBuilder = new class ($connection) extends \think\db\builder\Sqlite {
    public bool $intercepted = false;
    public function update(BaseQuery $query): string
    {
        if ($query->getOption('table') === 'pa_device_credential') {
            $this->intercepted = true;
            $query->whereRaw('1=0');
        }
        return parent::update($query);
    }
};
$beforeCas = $state();
$property->setValue($connection, $casBuilder);
try {
    businessReject(static fn() => Db::transaction($bindFresh), 'IDENTITY_DEVICE_UNAVAILABLE');
    $check($casBuilder->intercepted && $state() === $beforeCas, 'A lost binding CAS rolls back without recording an association or audit.');
} finally {
    $property->setValue($connection, $originalBuilder);
}
$bound = Db::transaction($bindFresh);
$check($bound->status === 'bound' && $bound->revision === '2', 'The original valid binding succeeds after exact restoration.');
Db::table('pa_device_credential')->where('credential_key', $key)->update([
    'status' => 'revoked', 'revision' => '3', 'revocation_epoch' => '1',
    'revoked_at' => '2026-11-07 01:00:00.000', 'revoked_by_member_id' => 1, 'revoke_reason' => 'synthetic revocation',
]);
$before = $state();
$asActor(static fn(TenantContext $trusted) => $bindings->assertRegistrationAccess($trusted, $key));
businessReject(static fn() => Db::transaction(static fn() => $bind()), 'IDENTITY_DEVICE_UNAVAILABLE');
$check($state() === $before && $enroll($input) === $created, 'Revocation retains authorized historical access while refusing a fresh binding operation.');
$check(!Db::connect()->getPdo()->inTransaction() && $contexts->isEmpty(), 'Public binding preserves the caller execution boundary.');
echo json_encode(['suite' => 'DeviceCredentialBinding', 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
