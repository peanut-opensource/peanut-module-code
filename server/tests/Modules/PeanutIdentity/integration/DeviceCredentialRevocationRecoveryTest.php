<?php

declare(strict_types=1);

use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotencyResult;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\RevokeDeviceCredentialInput;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialRevocationEvidence;
use PeanutAdmin\Modules\Identity\Device\RevokeDeviceCredential;
use think\facade\Db;

require __DIR__ . '/../support/DeviceCredentialRevocationFixture.php';

$enrolled = $enroll(deviceSigned($actor, 21001));
$key = $enrolled['resource_key'];
$input = $revokeInput(21002);
$first = $revoke($key, $input);
Db::table('pa_tenant_idempotency_record')->where('operation_key', 'peanut.identity.device-credential.revoke')->delete();
$before = $state();
$connection = Db::connect();
$originalBuilder = $connection->getBuilder();
$property = new ReflectionProperty($connection, 'builder');
$raceBuilder = static function (string $failure, ?Closure $beforeFailure = null) use ($connection) {
    return new class ($connection, $failure, $beforeFailure) extends \think\db\builder\Sqlite {
        public bool $hidden = false;
        public bool $failed = false;
        public function __construct($connection, private string $failure, private ?Closure $beforeFailure)
        {
            parent::__construct($connection);
        }
        public function select(\think\db\BaseQuery $query, bool $one = false): string
        {
            if ($query->getOption('table') !== 'pa_device_credential' || $this->failed) {
                return parent::select($query, $one);
            }
            if (!$this->hidden) {
                $this->hidden = true;
                $query->whereRaw('1=0');
                return parent::select($query, $one);
            }
            $this->failed = true;
            ($this->beforeFailure ?? static fn() => null)();
            if ($this->failure === 'business') {
                throw BusinessException::conflict('IDENTITY_DEVICE_REVISION_CONFLICT', 'Injected credential write race.');
            }
            $driver = match ($this->failure) {
                'deadlock' => 1213, 'timeout' => 1205, 'unrelated' => 1054, default => 1062,
            };
            $index = $this->failure === 'other-index' ? 'uq_device_credential_public_key' : 'uq_device_credential_revoke_command';
            $native = new PDOException('Injected ' . $index);
            $native->errorInfo = [
                $driver === 1062 ? '23000' : ($driver === 1054 ? '42S22' : '40001'), $driver, $native->getMessage(),
            ];
            throw new \think\db\exception\PDOException($native);
        }
    };
};
foreach (['business', 'deadlock', 'timeout', 'command-duplicate', 'other-index', 'unrelated'] as $failure) {
    $builder = $raceBuilder($failure);
    $property->setValue($connection, $builder);
    try {
        if (in_array($failure, ['other-index', 'unrelated'], true)) {
            try {
                $revoke($key, $input);
                throw new LogicException('Expected unrelated SQL error.');
            } catch (\think\db\exception\PDOException $error) {
                $check(
                    (int) $error->getData()['PDO Error Info']['Driver Error Code'] === ($failure === 'unrelated' ? 1054 : 1062),
                    'Only the exact command unique race or known transaction race may recover.',
                );
            }
        } else {
            $check($revoke($key, $input) === $first, 'Fresh original recovery after ' . $failure);
        }
        $check(
            $builder->hidden && $builder->failed && $state() === $before && !$connection->getPdo()->inTransaction(),
            'Injected stale reads and native failures preserve all permanent state and close only the owned transaction.',
        );
    } finally {
        $property->setValue($connection, $originalBuilder);
    }
}

$fresh = $enroll(deviceSigned($actor, 21003));
$before = $state();
$builder = $raceBuilder('deadlock');
$property->setValue($connection, $builder);
try {
    $pending = $revoke($fresh['resource_key'], $revokeInput(21004));
    $check($pending['outcome'] === 'pending' && $pending['data'] === null && $pending['problem']['recovery_action'] === 'query_original'
        && $state() === $before, 'A known race without an authoritative source remains pending.');
} finally {
    $property->setValue($connection, $originalBuilder);
}
$grants = $roles->grants;
$builder = $raceBuilder('business', static function () use ($roles): void {
    $roles->grants = [];
});
$property->setValue($connection, $builder);
try {
    businessReject(static fn() => $revoke($key, $input), 'IDENTITY_DEVICE_SCOPE_DENIED');
    $check($builder->failed && $state() === $before, 'Fresh recovery rechecks authorization after the failed transaction.');
} finally {
    $property->setValue($connection, $originalBuilder);
    $roles->grants = $grants;
}

$makeCommand = static fn(string $target, RevokeDeviceCredentialInput $candidate): IdempotencyCommand => IdempotencyCommand::tenant(
    $actor,
    'peanut.identity.device-credential.revoke',
    $candidate->commandKey,
    DeviceCredentialRevocationEvidence::inputHash($actor, $target, $candidate),
    new DateTimeImmutable('+1 day'),
);
$pendingInput = $revokeInput(21005);
$lease = $commands->begin($makeCommand($fresh['resource_key'], $pendingInput));
$before = $state();
$pending = $revoke($fresh['resource_key'], $pendingInput);
$check(
    $pending['outcome'] === 'pending' && $pending['resource_key'] === null && $state() === $before,
    'A real processing Core claim cannot create a revocation source.',
);
$commands->complete($lease, new IdempotencyReceipt(200, $first, 'peanut.identity.device-credential', $key));
$before = $state();
$check(
    $revoke($fresh['resource_key'], $pendingInput)['outcome'] === 'pending' && $state() === $before,
    'A completed temporary claim without matching permanent facts cannot supply another credential result.',
);
$mismatch = $revokeInput(21006);
$commands->begin(IdempotencyCommand::tenant(
    $actor,
    'peanut.identity.device-credential.revoke',
    $mismatch->commandKey,
    str_repeat('f', 64),
    new DateTimeImmutable('+1 day'),
));
$before = $state();
businessReject(static fn() => $revoke($fresh['resource_key'], $mismatch), 'IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH');
$check($state() === $before, 'A Core hash mismatch leaves the credential and all side effects untouched.');

foreach (['begin', 'complete'] as $stage) {
    $failing = new class ($commands, $stage) implements IdempotentCommandExecutor {
        public function __construct(private IdempotentCommandExecutor $actual, private string $stage) {}
        public function begin(IdempotencyCommand $command): IdempotencyResult
        {
            $result = $this->actual->begin($command);
            if ($this->stage === 'begin') {
                throw new RuntimeException('Injected Core begin failure.');
            }
            return $result;
        }
        public function complete(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
        {
            $this->actual->complete($execution, $receipt);
            throw new RuntimeException('Injected Core complete failure.');
        }
        public function fail(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
        {
            $this->actual->fail($execution, $receipt);
        }
    };
    $faultService = new RevokeDeviceCredential($access, $failing, $audit);
    $candidate = $revokeInput($stage === 'begin' ? 21007 : 21008);
    $before = $state();
    try {
        $asActor(static fn(TenantContext $trusted) => $faultService->revoke($trusted, $fresh['resource_key'], $candidate));
        throw new LogicException('Expected actual Core fault.');
    } catch (RuntimeException $error) {
        $check(
            $error->getMessage() === 'Injected Core ' . $stage . ' failure.' && $state() === $before,
            'Actual Core ' . $stage . ' writes roll back with the permanent revocation and audit.',
        );
    }
}
$audit->fail = true;
$before = $state();
try {
    $revoke($fresh['resource_key'], $revokeInput(21009));
    throw new LogicException('Expected audit failure.');
} catch (RuntimeException $error) {
    $check(
        $error->getMessage() === 'injected audit failure' && $state() === $before,
        'Audit failure after actual inserts rolls back the credential, permanent result and Core claim.',
    );
} finally {
    $audit->fail = false;
}


$casBuilder = new class ($connection) extends \think\db\builder\Sqlite {
    public bool $intercepted = false;
    public function update(\think\db\BaseQuery $query): string
    {
        if (!$this->intercepted && $query->getOption('table') === 'pa_device_credential') {
            $this->intercepted = true;
            $query->whereRaw('1=0');
        }
        return parent::update($query);
    }
};
$before = $state();
$property->setValue($connection, $casBuilder);
try {
    businessReject(static fn() => $revoke($fresh['resource_key'], $revokeInput(21014)), 'IDENTITY_DEVICE_REVISION_CONFLICT');
    $check(
        $casBuilder->intercepted && $state() === $before,
        'A real zero-row credential CAS rolls back the already-created Core claim.',
    );
} finally {
    $property->setValue($connection, $originalBuilder);
}

$before = $state();
$count = count($modules->calls);
businessReject(static fn() => $asActor(static fn(TenantContext $trusted) =>
    $revokeService->revokeInTransaction($trusted, $fresh['resource_key'], $revokeInput(21010))), 'IDENTITY_DEVICE_INVARIANT');
$check($state() === $before && count($modules->calls) === $count, 'The participant rejects an absent transaction before access or writes.');

Db::startTrans();
Db::table('business_test_audit')->insert(['tenant_id' => 1, 'event_type' => 'caller.outer']);
$outer = $state();
Db::startTrans();
Db::table('business_test_audit')->insert(['tenant_id' => 1, 'event_type' => 'caller.inner']);
$inner = $state();
businessReject(static fn() => $revoke($fresh['resource_key'], $revokeInput(21011)), 'IDENTITY_DEVICE_INVARIANT');
$check(
    $state() === $inner && count($modules->calls) === $count && $connection->getPdo()->inTransaction(),
    'The root entry preserves caller state and its actual nested savepoint before access.',
);
Db::rollback();
$check($state() === $outer && $connection->getPdo()->inTransaction(), 'The caller can independently roll back its real nested savepoint.');
Db::rollback();
$check($state() === $before && !$connection->getPdo()->inTransaction(), 'The caller retains ownership of the root rollback.');

Db::startTrans();
Db::table('business_test_audit')->insert(['tenant_id' => 1, 'event_type' => 'caller.outer']);
$outer = $state();
Db::startTrans();
$participantInput = $revokeInput(21012);
$accepted = $asActor(static fn(TenantContext $trusted): array =>
    $revokeService->revokeInTransaction($trusted, $fresh['resource_key'], $participantInput));
$check(
    $accepted['outcome'] === 'accepted' && $connection->getPdo()->inTransaction()
    && Db::table('pa_device_credential')->where('credential_key', $fresh['resource_key'])->value('status') === 'revoked',
    'The participant writes actual permanent facts within the caller savepoint.',
);
Db::rollback();
$check(
    $state() === $outer && $connection->getPdo()->inTransaction(),
    'Caller savepoint rollback removes actual revocation, audit and Core completion while retaining outer work.',
);
Db::rollback();
$check($state() === $before, 'Root caller rollback leaves all three tables at the original baseline.');

Db::startTrans();
Db::table('business_test_audit')->insert(['tenant_id' => 1, 'event_type' => 'caller.outer']);
$outer = $state();
Db::startTrans();
$audit->fail = true;
try {
    $asActor(static fn(TenantContext $trusted) =>
        $revokeService->revokeInTransaction($trusted, $fresh['resource_key'], $revokeInput(21013)));
    throw new LogicException('Expected participant audit failure.');
} catch (RuntimeException $error) {
    $check(
        $error->getMessage() === 'injected audit failure' && $connection->getPdo()->inTransaction()
        && Db::table('pa_device_credential')->where('credential_key', $fresh['resource_key'])->value('status') === 'revoked',
        'Participant failure propagates with the caller transaction intact and no internal recovery.',
    );
} finally {
    $audit->fail = false;
}
Db::rollback();
$check($state() === $outer && $connection->getPdo()->inTransaction(), 'The caller can roll back failed participant writes at its own savepoint.');
Db::rollback();
$check($state() === $before, 'Failed participant root rollback restores the exact baseline.');
$retry = $revoke($fresh['resource_key'], $participantInput);
$check(
    $retry['outcome'] === 'accepted' && $retry['resource_revision'] === '2'
    && !$connection->getPdo()->inTransaction() && $contexts->isEmpty(),
    'Only after caller rollback does a fresh root command execute once.',
);

echo json_encode(['suite' => 'DeviceCredentialRevocationRecovery', 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
