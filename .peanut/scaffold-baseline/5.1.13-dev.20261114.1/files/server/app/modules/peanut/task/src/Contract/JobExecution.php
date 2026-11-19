<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use Closure;
use Throwable;

final class JobExecution
{
    /** @var list<Closure(): void> */
    private array $authorizationChecks = [];

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $jobKey,
        public readonly int $tenantId,
        public readonly int $attemptNumber,
        public readonly array $payload,
        private readonly Closure $renewLease,
        private readonly Closure $assertLeaseOwned,
        private readonly ?Closure $reauthorize = null,
        private readonly ?Closure $readNegativeState = null,
        private readonly ?Closure $writeNegativeState = null,
    ) {}

    public function negativeState(string $reference): ?array
    {
        if ($this->readNegativeState === null) {
            throw new \DomainException('TASK_NEGATIVE_PROFILE_REQUIRED');
        }
        $this->assertLeaseOwned();
        return ($this->readNegativeState)($reference);
    }

    public function recordNegativeState(string $reference, array $state): void
    {
        if ($this->writeNegativeState === null) {
            throw new \DomainException('TASK_NEGATIVE_PROFILE_REQUIRED');
        }
        $this->assertLeaseOwned();
        ($this->writeNegativeState)($reference, $state);
    }

    /**
     * Adds a current authorization condition owned by an outer execution boundary.
     * Checks can only tighten execution and are shared by the final worker fence.
     *
     * @param Closure(): void $check
     */
    public function addAuthorizationCheck(Closure $check): void
    {
        $this->authorizationChecks[] = $check;
    }

    /**
     * Renew the current claim before the next bounded batch or side effect.
     * A lost or expired lease throws and the handler must stop immediately.
     */
    public function checkpoint(): void
    {
        try {
            ($this->renewLease)();
        } catch (Throwable $exception) {
            throw new LeaseLostException(previous: $exception);
        }
        $this->assertAuthorized();
    }

    /**
     * Fence a result commit after work that may have crossed the lease limit.
     */
    public function assertLeaseOwned(): void
    {
        try {
            ($this->assertLeaseOwned)();
        } catch (Throwable $exception) {
            throw new LeaseLostException(previous: $exception);
        }
        $this->assertAuthorized();
    }

    private function assertAuthorized(): void
    {
        // The null default is only for direct non-authorized fixtures. LocalWorker
        // always supplies signed-envelope reauthorization in production.
        if ($this->reauthorize !== null) {
            ($this->reauthorize)();
        }
        foreach ($this->authorizationChecks as $check) {
            $check();
        }
    }
}
