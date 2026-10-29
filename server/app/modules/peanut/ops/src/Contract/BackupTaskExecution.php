<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Contract;

/**
 * Trusted deployment-worker capability, not a user API or permission grant.
 * The original implementation retains claim fencing, operator checks, audit and evidence validation.
 * Inputs are fixed task identities and verified evidence; no arbitrary SQL, commands or paths.
 */
interface BackupTaskExecution
{
    /** @return array{task_key:string,backup_reference_key:string,provider_key:string,execution_revision:int}|null */
    public function claim(): ?array;

    /** @return array<string, mixed> */
    public function heartbeat(string $taskKey, int $executionRevision): array;

    /** @return array<string, mixed> */
    public function succeed(string $taskKey, int $executionRevision, string $manifestJson): array;

    /** @return array<string, mixed> */
    public function fail(string $taskKey, int $executionRevision, string $errorCode): array;
}
