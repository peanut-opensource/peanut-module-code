<?php

declare(strict_types=1);

namespace app\common\persistence;

use app\common\value\runtime\RuntimeNamespace;
use think\facade\Db;

/** Executes one callback while holding a bounded MySQL advisory lock. */
final class AdvisoryLockExecution
{
    public function run(string $name, int $timeoutSeconds, callable $operation): mixed
    {
        if ($name === '' || $timeoutSeconds < 0 || $timeoutSeconds > 30) {
            throw new \InvalidArgumentException('DATABASE_ADVISORY_LOCK_INVALID');
        }

        $name = RuntimeNamespace::fromConfiguration()->advisoryLockName($name);
        $connection = Db::connect();
        // Compose lifecycle owners without incrementing MySQL's recursive lock count.
        $owner = $connection->query('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [$name], true);
        if ((int) ($owner[0]['owned'] ?? 0) === 1) {
            return $operation();
        }
        $lock = $connection->query('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeoutSeconds], true);
        if ((int) ($lock[0]['acquired'] ?? 0) !== 1) {
            throw new AdvisoryLockUnavailable('DATABASE_ADVISORY_LOCK_UNAVAILABLE');
        }

        try {
            return $operation();
        } finally {
            try {
                $connection->query('SELECT RELEASE_LOCK(?)', [$name], true);
            } catch (\Throwable) {
            }
        }
    }
}
