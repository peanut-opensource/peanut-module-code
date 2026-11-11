<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Contract;

/**
 * Fixed deployment task transitions for an already registered and authorized Module request.
 * This port does not expose the request store, allow arbitrary commands or grant lifecycle permission.
 */
interface ModuleTaskExecution
{
    /** Consume at most one configured application-local development request. @return array<string,mixed>|null */
    public function work(): ?array;

    /** @return array<string, mixed>|null */
    public function claim(): ?array;

    /** @return array<string, mixed> */
    public function advance(string $taskKey, int $revision): array;

    /** @return array<string, mixed> */
    public function execute(string $taskKey, int $revision): array;

    /** @return array<string, mixed> */
    public function heartbeat(string $taskKey, int $revision): array;

    /** @return array<string, mixed> */
    public function succeed(string $taskKey, int $revision): array;

    /** @return array<string, mixed> */
    public function fail(string $taskKey, int $revision, string $errorCode): array;
}
