<?php

declare(strict_types=1);

use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialRevocationEvidence;
use think\facade\Db;

require __DIR__ . '/../support/DeviceCredentialRevocationFixture.php';

$enrolled = $enroll(deviceSigned($actor, 23001));
$key = $enrolled['resource_key'];
$input = $revokeInput(23002);
$connection = Db::connect();
$original = $connection->getBuilder();
$property = new ReflectionProperty($connection, 'builder');
$builder = new class ($connection) extends \think\db\builder\Sqlite {
    public bool $hideNextSource = false;
    public int $hiddenSources = 0;
    public int $lockedCredentialRoots = 0;
    public int $ordinarySources = 0;
    public function select(\think\db\BaseQuery $query, bool $one = false): string
    {
        if ($query->getOption('table') === 'pa_device_credential') {
            $where = json_encode($query->getOption('where'), JSON_THROW_ON_ERROR);
            $locked = (bool) $query->getOption('lock');
            if (str_contains($where, '"revoke_source_command_key"')) {
                if ($locked) {
                    throw new LogicException('Revocation command lookup must not acquire a missing-key range lock.');
                }
                $this->ordinarySources++;
                if ($this->hideNextSource) {
                    $this->hideNextSource = false;
                    $this->hiddenSources++;
                    $query->whereRaw('1=0');
                }
            } elseif ($locked && str_contains($where, '"credential_key"')) {
                $this->lockedCredentialRoots++;
            }
        }
        return parent::select($query, $one);
    }
};
$property->setValue($connection, $builder);
try {
    $first = $revoke($key, $input);
    $check(
        $first['outcome'] === 'accepted' && $builder->ordinarySources > 0 && $builder->lockedCredentialRoots === 1,
        'The command reads immutable sources normally and locks the exact credential root before writes.',
    );
    Db::table('pa_tenant_idempotency_record')->where('operation_key', 'peanut.identity.device-credential.revoke')->delete();
    $before = $state();
    $builder->hideNextSource = true;
    $check(
        $revoke($key, $input) === $first && $builder->hiddenSources === 1 && $builder->lockedCredentialRoots === 2,
        'After an explicit stale source miss, the current locked credential itself recovers the original command.',
    );
    $check($state() === $before, 'Known-root recovery creates no extra Core, source or audit.');
    $other = $enroll(deviceSigned($actor, 23003));
    $before = $state();
    businessReject(static fn() => $revoke($other['resource_key'], $input), 'IDENTITY_DEVICE_IDEMPOTENCY_MISMATCH');
    $check($state() === $before, 'The same original key on another actual root cannot return the first credential result.');

    $pendingInput = $revokeInput(23004);
    $lease = $commands->begin(IdempotencyCommand::tenant(
        $actor,
        'peanut.identity.device-credential.revoke',
        $pendingInput->commandKey,
        DeviceCredentialRevocationEvidence::inputHash($actor, $other['resource_key'], $pendingInput),
        new DateTimeImmutable('+1 day'),
    ));
    $commands->complete($lease, new IdempotencyReceipt(200, $first, 'peanut.identity.device-credential', $key));
    $before = $state();
    $pending = $revoke($other['resource_key'], $pendingInput);
    $check(
        $pending['outcome'] === 'pending' && $pending['data'] === null && $state() === $before,
        'A temporary completed result without permanent evidence does not lock an absent source or invent a result.',
    );
    $check(!$connection->getPdo()->inTransaction() && $contexts->isEmpty(), 'Every path restores actual context and transaction ownership.');
} finally {
    $property->setValue($connection, $original);
}
echo json_encode(['suite' => 'DeviceCredentialRevocationLocking', 'checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
