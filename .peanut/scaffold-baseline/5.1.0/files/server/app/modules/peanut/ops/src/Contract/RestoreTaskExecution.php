<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Contract;

/**
 * Trusted isolated-verification worker capability; no new restoration authority or resource selector.
 * Existing claim fencing, operator authorization, target isolation and evidence checks remain mandatory.
 */
interface RestoreTaskExecution
{
    /** @return array<string, mixed>|null */
    public function claim(): ?array;

    /** @return array<string, mixed> */
    public function verifyManifest(string $taskKey, int $executionRevision, string $manifestJson): array;

    /** @return array<string, mixed> */
    public function heartbeat(string $taskKey, int $executionRevision): array;

    /** @return array<string, mixed> */
    public function succeed(string $taskKey, int $executionRevision, string $evidenceJson): array;

    /** @return array<string, mixed> */
    public function fail(string $taskKey, int $executionRevision, string $errorCode): array;
}
