<?php

declare(strict_types=1);

use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotencyResult;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\EnrollDeviceCredentialInput;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialProof;
use PeanutAdmin\Modules\Identity\Device\EnrollDeviceCredential;
use think\facade\Db;

require __DIR__ . '/../support/DeviceCredentialFixture.php';

$checks = 0;
$input = deviceSigned($actor, 1201);
$first = $enroll($input);
Db::table('pa_tenant_idempotency_record')->where('operation_key', 'peanut.identity.device-credential.enroll')->delete();
$before = $state();
$connection = Db::connect();
$originalBuilder = $connection->getBuilder();
$property = new ReflectionProperty($connection, 'builder');
foreach (['business', 'deadlock', 'timeout', 'command-duplicate', 'public-key-duplicate', 'unrelated'] as $failure) {
    $builder = new class ($connection, $failure) extends \think\db\builder\Sqlite {
        public bool $hidden = false;
        public bool $failed = false;
        public function __construct($connection, private string $failure)
        {
            parent::__construct($connection);
        }
        public function select(\think\db\BaseQuery $query, bool $one = false): string
        {
            if (!$this->hidden && $query->getOption('table') === 'pa_device_credential') {
                $this->hidden = true;
                $query->whereRaw('1=0');
            } elseif ($this->hidden && !$this->failed && $query->getOption('table') === 'pa_device_credential') {
                $this->failed = true;
                if ($this->failure === 'business') {
                    throw BusinessException::conflict('IDENTITY_DEVICE_KEY_REGISTERED', 'injected key race');
                }
                $driver = match ($this->failure) {
                    'deadlock' => 1213, 'timeout' => 1205, 'unrelated' => 1054, default => 1062,
                };
                $index = $this->failure === 'public-key-duplicate' ? 'uq_device_credential_public_key' : 'uq_device_credential_enroll_command';
                $native = new PDOException('injected ' . $index);
                $native->errorInfo = [$driver === 1062 ? '23000' : ($driver === 1054 ? '42S22' : '40001'), $driver, $native->getMessage()];
                throw new \think\db\exception\PDOException($native);
            }
            return parent::select($query, $one);
        }
    };
    $property->setValue($connection, $builder);
    try {
        if ($failure === 'unrelated') {
            try {
                $enroll($input);
                throw new LogicException('Expected unrelated SQL failure.');
            } catch (\think\db\exception\PDOException $error) {
                businessCheck(
                    (int) $error->getData()['PDO Error Info']['Driver Error Code'] === 1054,
                    'Unclassified SQL errors propagate even with a known index in their text.',
                );
            }
        } else {
            businessCheck($enroll($input) === $first, 'Fresh recovery preserves the original enrollment after ' . $failure);
        }
        businessCheck($builder->hidden && $builder->failed && $state() === $before, 'A stale miss rolls back actual Core writes before permanent recovery.');
        $checks++;
    } finally {
        $property->setValue($connection, $originalBuilder);
    }
}
$makeCommand = static fn(EnrollDeviceCredentialInput $input): IdempotencyCommand => IdempotencyCommand::tenant(
    $actor,
    'peanut.identity.device-credential.enroll',
    $input->commandKey,
    hash('sha256', DeviceCredentialProof::message('1', '1', '1', 'admin-web', $input) . $input->credentialSignature),
    new DateTimeImmutable('+1 day'),
);
$pendingInput = deviceSigned($actor, 1202);
$lease = $commands->begin($makeCommand($pendingInput));
$before = $state();
$pending = $enroll($pendingInput);
businessCheck($pending['outcome'] === 'pending' && $pending['data'] === null && $pending['resource_key'] === null
    && $pending['problem']['recovery_action'] === 'query_original' && $state() === $before, 'An actual Core processing claim returns full pending without inventing a credential.');
$checks++;
$commands->complete($lease, new IdempotencyReceipt(201, ['temporary' => 'unverified'], 'peanut.identity.device-credential', businessKey(9000)));
$before = $state();
businessCheck($enroll($pendingInput)['outcome'] === 'pending' && $state() === $before, 'A temporary success without permanent proof remains pending.');
$checks++;
$mismatch = deviceSigned($actor, 1203);
$commands->begin(IdempotencyCommand::tenant(
    $actor,
    'peanut.identity.device-credential.enroll',
    $mismatch->commandKey,
    str_repeat('f', 64),
    new DateTimeImmutable('+1 day'),
));
$before = $state();
businessReject(static fn() => $enroll($mismatch), 'IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH');
businessCheck($state() === $before, 'Core hash mismatch preserves all persistent tables.');
$checks++;

foreach (['begin', 'complete'] as $stage) {
    $failing = new class ($commands, $stage) implements IdempotentCommandExecutor {
        public function __construct(private IdempotentCommandExecutor $actual, private string $stage) {}
        public function begin(IdempotencyCommand $command): IdempotencyResult
        {
            $result = $this->actual->begin($command);
            if ($this->stage === 'begin') {
                throw new RuntimeException('injected Core begin failure');
            }
            return $result;
        }
        public function complete(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
        {
            $this->actual->complete($execution, $receipt);
            throw new RuntimeException('injected Core complete failure');
        }
        public function fail(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
        {
            $this->actual->fail($execution, $receipt);
        }
    };
    $faultService = new EnrollDeviceCredential($access, $failing, $audit);
    $fresh = deviceSigned($actor, $stage === 'begin' ? 1204 : 1205);
    $before = $state();
    try {
        $asActor(static fn(TenantContext $trusted) => $faultService->enroll($trusted, $fresh));
        throw new LogicException('Expected native Core fault.');
    } catch (RuntimeException $error) {
        businessCheck(
            $error->getMessage() === 'injected Core ' . $stage . ' failure' && $state() === $before,
            'Actual Core ' . $stage . ' failure rolls back every enrollment effect.',
        );
        $checks++;
    }
}
$audit->fail = true;
$before = $state();
try {
    $enroll(deviceSigned($actor, 1206));
    throw new LogicException('Expected audit failure.');
} catch (RuntimeException $error) {
    businessCheck($error->getMessage() === 'injected audit failure' && $state() === $before, 'Audit fault rolls back the actual credential source and Core claim.');
    $checks++;
}
$audit->fail = false;

$before = $state();
$count = count($modules->calls);
Db::startTrans();
Db::table('business_test_audit')->insert(['tenant_id' => 1, 'event_type' => 'caller.outer']);
Db::startTrans();
Db::table('business_test_audit')->insert(['tenant_id' => 1, 'event_type' => 'caller.inner']);
businessReject(static fn() => $enroll(deviceSigned($actor, 1207)), 'IDENTITY_DEVICE_INVARIANT');
businessCheck(Db::connect()->getPdo()->inTransaction() && count($modules->calls) === $count
    && Db::table('business_test_audit')->where('event_type', 'caller.outer')->count() === 1
    && Db::table('business_test_audit')->where('event_type', 'caller.inner')->count() === 1, 'Root guard preserves the actual caller transaction and nested savepoint.');
Db::commit();
businessCheck(Db::connect()->getPdo()->inTransaction(), 'The caller retains its outer transaction.');
Db::rollback();
businessCheck($state() === $before && $contexts->isEmpty(), 'Caller rollback restores only caller-owned changes.');
$checks++;
echo json_encode(['suite' => 'DeviceCredentialRecovery', 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
