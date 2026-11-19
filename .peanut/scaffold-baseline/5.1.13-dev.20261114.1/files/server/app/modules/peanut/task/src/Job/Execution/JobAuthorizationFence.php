<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Job\Execution;

use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;

/** Revalidates one claimed job against its original signed authorization envelope. */
final readonly class JobAuthorizationFence
{
    public function __construct(
        private JobHandlerAdapter $authorization,
        private JobClaim $claim,
        private ?string $negativeSigningKey = null,
    ) {}

    public function negativeEnvelope(): NegativeFactJobEnvelope
    {
        if ($this->claim->profile !== NegativeFactJobEnvelope::PROFILE || $this->negativeSigningKey === null) {
            throw TaskJobException::denied();
        }
        $envelope = NegativeFactJobEnvelope::verify($this->claim->trustedEnvelope, $this->negativeSigningKey);
        $payload = $this->claim->payload;
        ksort($payload, SORT_STRING);
        if ($envelope->tenantId !== $this->claim->tenantId || $envelope->jobKey !== $this->claim->jobKey
            || $this->claim->handlerKey !== NegativeFactJobEnvelope::TASK
            || $payload !== ['fact_id' => $envelope->factId, 'fact_sha256' => $envelope->factHash, 'purpose' => NegativeFactJobEnvelope::PURPOSE, 'tenant_id' => $envelope->tenantId]) {
            throw TaskJobException::denied();
        }
        return $envelope;
    }

    public function assertNegativeAuthorized(\PeanutAdmin\Modules\Task\Contract\NegativeFactTaskHandler $handler): NegativeFactJobEnvelope
    {
        $envelope = $this->negativeEnvelope();
        $handler->validateNegativeFact($envelope);
        return $envelope;
    }

    /**
     * @template T
     * @param callable(AuthorizedOperationContext, VerifiedJobEnvelope): T $handler
     * @return T
     */
    public function handle(callable $handler): mixed
    {
        try {
            [$context, $envelope] = $this->authorization->handle(
                $this->claim->trustedEnvelope,
                function (AuthorizedOperationContext $context, VerifiedJobEnvelope $envelope): array {
                    $this->assertAuthorizedContext($context, $envelope);
                    return [$context, $envelope];
                },
            );
        } catch (AuthException) {
            throw TaskJobException::denied();
        }

        return $handler($context, $envelope);
    }

    public function assertAuthorized(): void
    {
        $this->handle(static fn(): null => null);
    }

    private function assertAuthorizedContext(
        AuthorizedOperationContext $context,
        VerifiedJobEnvelope $envelope,
    ): void {
        if ($envelope->tenantId !== $this->claim->tenantId
            || $context->tenantContext->tenantId !== $envelope->tenantId
            || $context->tenantContext->accountId !== $envelope->accountId
            || $context->tenantContext->memberId !== $envelope->memberId
            || !hash_equals($context->resourceKey, $envelope->resourceKey)
            || !hash_equals($context->operation, $envelope->operation)
            || !hash_equals($envelope->operationId, $this->claim->jobKey)
            || $this->canonicalTargets($context->targets) !== $this->canonicalTargets($envelope->requestedTargets)
        ) {
            throw TaskJobException::denied();
        }
    }

    /**
     * @param list<RequestedTargetSet> $sets
     * @return list<array{target_resource_key: string, target_role: string, target_ids: non-empty-list<string>}>
     */
    private function canonicalTargets(array $sets): array
    {
        $normalized = array_map(
            static fn(RequestedTargetSet $set): array => $set->toArray(),
            $sets,
        );
        usort($normalized, static fn(array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
        return $normalized;
    }
}
