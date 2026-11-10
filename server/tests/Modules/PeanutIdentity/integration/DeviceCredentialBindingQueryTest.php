<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Contract\DeviceCredentialBindingQueries;
use PeanutAdmin\Modules\Identity\Device\BindDeviceCredential;
use PeanutAdmin\Modules\Identity\ModuleProvider;
use think\db\BaseQuery;
use think\db\exception\PDOException;
use think\facade\Db;

require __DIR__ . '/../support/DeviceCredentialRevocationFixture.php';

$check($bindings instanceof DeviceCredentialBindingQueries, 'The published query reuses the actual binding producer.');
$provider = new ModuleProvider();
$manifest = json_decode((string) file_get_contents($identityModule . '/module.json'), true, flags: JSON_THROW_ON_ERROR);
$check($provider->bindings()[DeviceCredentialBindingQueries::class] === BindDeviceCredential::class
    && in_array(DeviceCredentialBindingQueries::class, $manifest['contracts']['exports'], true), 'The production provider and manifest expose the exact narrow interface.');

$input = deviceSigned($actor, 25001);
$created = $enroll($input);
$key = $created['resource_key'];
$installation = businessKey(25002);
$query = static function (array $changes = [], ?TenantContext $current = null) use ($asActor, $bindings, $key, $installation, $input) {
    $arguments = array_replace([
        'credential_key' => $key, 'installation_key' => $installation,
        'client_key' => $input->clientKey, 'device_fingerprint_hash' => $input->deviceFingerprintHash,
    ], $changes);
    return $asActor(static fn(TenantContext $trusted) => $bindings->lockActiveInstallationBinding(
        $trusted,
        $arguments['credential_key'],
        $arguments['installation_key'],
        $arguments['client_key'],
        $arguments['device_fingerprint_hash'],
    ), $current);
};
$before = $state();
businessReject($query, 'IDENTITY_DEVICE_INVARIANT');
$check($state() === $before, 'A missing caller transaction rejects without credential, audit or Core writes.');
businessReject(static fn() => Db::transaction(static fn() => $query()), 'IDENTITY_DEVICE_UNAVAILABLE');
$check($state() === $before, 'A real enrolled credential cannot be reported as currently bound.');

$bound = Db::transaction(static fn() => $asActor(static fn(TenantContext $trusted) => $bindings->bindInstallation(
    $trusted,
    $key,
    $installation,
    $input->clientKey,
    $input->deviceFingerprintHash,
)));
$before = $state();
$connection = Db::connect();
$originalBuilder = $connection->getBuilder();
$property = new ReflectionProperty($connection, 'builder');
$builder = new class ($connection) extends \think\db\builder\Sqlite {
    public array $locks = [];
    public bool $failRead = false;
    public function select(BaseQuery $query, bool $one = false): string
    {
        $sql = parent::select($query, $one);
        if ($this->failRead && $query->getOption('table') === 'pa_device_credential') {
            return $sql . ' F50_UNSUPPORTED_READ';
        }
        return $sql;
    }
    protected function parseLock(BaseQuery $query, bool|string $lock = false): string
    {
        if ($query->getOption('table') === 'pa_device_credential') {
            $this->locks[] = [$lock, Db::connect()->getPdo()->inTransaction(), $query->getOption('where')];
        }
        return parent::parseLock($query, $lock);
    }
};
$property->setValue($connection, $builder);
try {
    $reference = Db::transaction(static fn() => $query());
    $check(get_object_vars($reference) === get_object_vars($bound) && count(get_object_vars($reference)) === 9, 'Current query returns the exact nine original bound fields.');
    $check(count($builder->locks) === 1 && $builder->locks[0][0] && $builder->locks[0][1]
        && str_contains(json_encode($builder->locks[0][2], JSON_THROW_ON_ERROR), 'tenant_id')
        && str_contains(json_encode($builder->locks[0][2], JSON_THROW_ON_ERROR), 'credential_key'), 'The actual ORM uses one current same-tenant credential point lock inside the caller transaction.');
    $check(end($modules->calls) === ['peanut.identity', true, true], 'The protected Identity deployment is locked in the same transaction.');
    $check($state() === $before, 'Successful current queries preserve every credential, audit and Core record.');
    $check(get_object_vars(Db::transaction(static fn() => $query([], businessActor(1, 2)))) === get_object_vars($bound), 'Another currently authorized tenant member can consume the public reference.');

    foreach ([
        [['installation_key' => businessKey(25003)], 'IDENTITY_DEVICE_BINDING_MISMATCH'],
        [['client_key' => 'dcs-count-web'], 'IDENTITY_DEVICE_BINDING_MISMATCH'],
        [['device_fingerprint_hash' => str_repeat('f', 64)], 'IDENTITY_DEVICE_BINDING_MISMATCH'],
        [['credential_key' => businessKey(25999)], 'IDENTITY_DEVICE_NOT_FOUND'],
        [['credential_key' => 'invalid'], 'IDENTITY_DEVICE_INPUT_INVALID'],
        [['installation_key' => 'invalid'], 'IDENTITY_DEVICE_INPUT_INVALID'],
        [['client_key' => 'INVALID'], 'IDENTITY_DEVICE_INPUT_INVALID'],
        [['device_fingerprint_hash' => 'invalid'], 'IDENTITY_DEVICE_INPUT_INVALID'],
    ] as [$arguments, $error]) {
        businessReject(static fn() => Db::transaction(static fn() => $query($arguments)), $error);
        $check($state() === $before, 'Invalid or mismatched current association has no side effects.');
    }
    foreach ([$key, businessKey(25999)] as $foreign) {
        businessReject(static fn() => Db::transaction(static fn() => $query(['credential_key' => $foreign], businessActor(2, 20))), 'IDENTITY_DEVICE_NOT_FOUND');
        $checks++;
    }
    businessReject(static fn() => Db::transaction(static fn() => $bindings->lockActiveInstallationBinding(
        $actor,
        $key,
        $installation,
        $input->clientKey,
        $input->deviceFingerprintHash,
    )), 'IDENTITY_DEVICE_SCOPE_DENIED');
    businessReject(static fn() => $contexts->run(
        new AdminExecutionContext($actor, 'cloned-context'),
        static fn() => Db::transaction(static fn() => $bindings->lockActiveInstallationBinding(
            businessActor(),
            $key,
            $installation,
            $input->clientKey,
            $input->deviceFingerprintHash,
        )),
    ), 'IDENTITY_DEVICE_SCOPE_DENIED');
    $checks += 2;
    $deviceActor = TenantContext::fromValidatedSession(
        new ValidatedTenantSession(1, 'fixture-device', 1, 1, 1, 'dcs-pos', new DateTimeImmutable(), 1, 1),
        'device-query-context',
    );
    businessReject(static fn() => Db::transaction(static fn() => $query([], $deviceActor)), 'IDENTITY_DEVICE_SCOPE_DENIED');
    $checks++;
    $grants = $roles->grants;
    $roles->grants = ['peanut.identity.device-credential.enroll', 'peanut.identity.device-credential.revoke'];
    $reads = count($builder->locks);
    businessReject(static fn() => Db::transaction(static fn() => $query()), 'IDENTITY_DEVICE_SCOPE_DENIED');
    $roles->grants = $grants;
    $check(count($builder->locks) === $reads, 'Current permission denies before any credential source read.');
    $check($state() === $before, 'Enrollment or revocation authority cannot replace current binding permission.');
    $modules->available = false;
    try {
        Db::transaction(static fn() => $query());
        throw new LogicException('Expected deployment rejection.');
    } catch (ModuleException $error) {
        $check($error->getMessage() === 'Identity unavailable.', 'Deployment failure retains its actual native error.');
    } finally {
        $modules->available = true;
    }

    $source = Db::table('pa_device_credential')->where('credential_key', $key)->find();
    $earlier = (new DateTimeImmutable($source['created_at'], new DateTimeZone('UTC')))->modify('-1 millisecond')->format('Y-m-d H:i:s.v');
    foreach ([
        ['revision' => '1'], ['revision' => '0'], ['revision' => '02'], ['revision' => "2\n"],
        ['revision' => '18446744073709551616'], ['bound_by_member_id' => 0], ['bound_by_member_id' => -1],
        ['bound_at' => null], ['bound_at' => $earlier], ['bound_at' => '2026-02-30 12:00:00.000'],
        ['bound_at' => '2026-11-07 24:00:00.000'], ['bound_at' => '2026-11-07 12:00:60.000'],
        ['bound_at' => '0999-01-01 00:00:00.000'], ['bound_at' => "2026-11-07 12:00:00.000\n"],
        ['revoked_at' => $source['bound_at']], ['revoked_by_member_id' => 2], ['revoke_reason' => 'damaged revocation residue'],
        ['enroll_basis_json' => '{}'], ['enroll_input_hash' => str_repeat('f', 64)],
    ] as $damage) {
        // A deliberate isolated corruption fixture bypasses CHECK only while
        // installing the bad row. The actual production query must reject it.
        Db::execute('PRAGMA ignore_check_constraints = ON');
        try {
            Db::table('pa_device_credential')->where('credential_key', $key)->update($damage);
        } finally {
            Db::execute('PRAGMA ignore_check_constraints = OFF');
        }
        $corrupt = $state();
        try {
            businessReject(static fn() => Db::transaction(static fn() => $query()), 'IDENTITY_DEVICE_INVARIANT');
            $check($state() === $corrupt, 'Malformed current or original evidence is rejected without repairs or writes.');
        } finally {
            Db::table('pa_device_credential')->where('credential_key', $key)->update(array_intersect_key($source, $damage));
        }
    }
    Db::table('pa_device_credential')->where('credential_key', $key)->update(['revision' => '18446744073709551615']);
    $check(Db::transaction(static fn() => $query())->revision === '18446744073709551615', 'The published exact uint64 upper bound remains a string.');
    Db::table('pa_device_credential')->where('credential_key', $key)->update(['revision' => $source['revision']]);

    Db::startTrans();
    try {
        Db::table('pa_device_credential')->where('credential_key', $key)->update(['updated_by_member_id' => 2]);
        $callerState = $state();
        $reference = $query();
        $check($connection->getPdo()->inTransaction() && $state() === $callerState
            && $reference->installationKey === $installation, 'A successful query leaves the caller transaction and its own write intact.');
        $builder->failRead = true;
        try {
            $query();
            throw new LogicException('Expected the real native SQL failure.');
        } catch (PDOException $error) {
            $builder->failRead = false;
            $check(str_contains($error->getMessage(), 'F50_UNSUPPORTED_READ')
                && $connection->getPdo()->inTransaction() && $state() === $callerState, 'Native SQL failure propagates while the original caller transaction and write remain owned.');
        } finally {
            $builder->failRead = false;
        }
    } finally {
        Db::rollback();
    }
    $check($state() === $before, 'Only the caller rollback removes its own temporary change.');

    $countInput = deviceSigned($actor, 25005, client: 'dcs-count-web');
    $countCreated = $enroll($countInput);
    $countInstallation = businessKey(25006);
    $countBound = Db::transaction(static fn() => $asActor(static fn(TenantContext $trusted) => $bindings->bindInstallation(
        $trusted,
        $countCreated['resource_key'],
        $countInstallation,
        $countInput->clientKey,
        $countInput->deviceFingerprintHash,
    )));
    $countBefore = $state();
    $countReference = Db::transaction(static fn() => $query([
        'credential_key' => $countCreated['resource_key'], 'installation_key' => $countInstallation,
        'client_key' => $countInput->clientKey, 'device_fingerprint_hash' => $countInput->deviceFingerprintHash,
    ]));
    $check(
        get_object_vars($countReference) === get_object_vars($countBound) && $state() === $countBefore,
        'A real inventory client binding uses the same public producer without writes.',
    );

    $revoked = $revoke($key, $revokeInput(25004, '2'));
    $revokedState = $state();
    businessReject(static fn() => Db::transaction(static fn() => $query()), 'IDENTITY_DEVICE_UNAVAILABLE');
    $asActor(static fn(TenantContext $trusted) => $bindings->assertRegistrationAccess($trusted, $key));
    $check($revoked['data']['revocation_epoch'] === '1' && $enroll($input) === $created && $state() === $revokedState, 'Actual revocation denies new current qualification and preserves authorized original registration recovery.');
    $check(!$connection->getPdo()->inTransaction() && $contexts->isEmpty(), 'All paths restore the caller execution boundary.');
} finally {
    $property->setValue($connection, $originalBuilder);
}
echo json_encode(['suite' => 'DeviceCredentialBindingQuery', 'checks' => $checks, 'scope' => 'native enrollment, binding, revocation, ORM locks, permissions and caller-owned transaction'], JSON_THROW_ON_ERROR) . PHP_EOL;
