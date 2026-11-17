<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Contract;

/**
 * Trusted worker for the existing fixed upgrade state machine; no package/path/command selection.
 * Registration is not permission to upgrade: original fencing, operator checks and evidence remain.
 */
interface UpgradeTaskExecution
{
    /** @return array<string, mixed>|null */
    public function claim(): ?array;

    /** @return array<string, mixed> */
    public function advance(string $taskKey, int $revision): array;

    /** @return array<string, mixed> */
    public function heartbeat(string $taskKey, int $revision): array;

    /** @return array<string, mixed> */
    public function succeed(string $taskKey, int $revision): array;

    /** @return array<string, mixed> */
    public function fail(string $taskKey, int $revision, string $errorCode): array;
}
