<?php

declare(strict_types=1);

namespace app\common\infrastructure\idempotency;

use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotencyResult;
use LogicException;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

final readonly class ThinkPhpIdempotentCommandExecutor implements IdempotentCommandExecutor
{
    public function __construct(private IdempotencyService $idempotency) {}

    public function begin(IdempotencyCommand $command): IdempotencyResult
    {
        return IdempotencyResult::fromRecord(
            $this->idempotency->beginTenant(
                $this->scope($command->context->tenantId),
                $command->context->memberId,
                $command->operationKey,
                $command->key,
                $command->requestHash,
                $command->expiresAt,
            ),
            $command->context->tenantId,
        );
    }

    public function complete(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
    {
        $this->assertExecutionOwner($execution);
        $this->idempotency->completeTenant(
            $this->scope($execution->tenantId()),
            $execution->id(),
            $receipt->status,
            $receipt->body,
            $receipt->resourceType,
            $receipt->resourceId,
        );
    }

    public function fail(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
    {
        $this->assertExecutionOwner($execution);
        $this->idempotency->failTenant(
            $this->scope($execution->tenantId()),
            $execution->id(),
            $receipt->status,
            $receipt->body,
            $receipt->resourceType,
            $receipt->resourceId,
        );
    }

    private function assertExecutionOwner(IdempotencyResult $execution): void
    {
        if (!$execution->isExecutionOwner()) {
            throw new LogicException('Only the idempotency execution owner may finalize a command.');
        }
    }

    private function scope(int $tenantId): TenantScope
    {
        return TenantScope::fromTrustedContext($tenantId, 'application-idempotency');
    }
}
