<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use think\facade\Db;

/** Business idempotency scope and outer transaction ownership, including replay authorization. */
final readonly class WorkflowCommandTransaction
{
    public function __construct(private IdempotencyService $idempotency, private WorkflowLifecycleQueries $lifecycle) {}

    public function run(AuthorizedOperationContext $context, string $operation, string $target, array $request, string $rawKey, callable $authorizeRead, callable $execute): array
    {
        if ($context->tenantContext->tenantId < 1 || $context->tenantContext->memberId < 1) {
            throw WorkflowException::subjectNotFound();
        }
        // Replays must not expose an old receipt after current access is withdrawn.
        $authorizeRead();
        $key = IdempotencyKey::fromString($rawKey);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return Db::transaction(function () use ($context, $operation, $target, $request, $key, $now, $execute): array {
            $this->lifecycle->assertAvailable($context->tenantContext->tenantId, true);
            $scope = TenantScope::fromTrustedContext($context->tenantContext->tenantId, 'workflow-command');
            $record = $this->idempotency->beginTenant($scope, $context->tenantContext->memberId, $operation . ':' . hash('sha256', $target), $key, hash('sha256', WorkflowGraph::json($request)), $now->modify('+1 day'), $now);
            if ($record->replayable()) {
                return (array) $record->responseBody;
            }
            if (!$record->acquiredForExecution()) {
                throw new ApiException('IDEMPOTENCY_REQUEST_PROCESSING', 409, 'Workflow request is processing.');
            }
            $receipt = $execute($now->format('Y-m-d H:i:s.v'));
            $this->idempotency->completeTenant($scope, $record->id, 200, $receipt, 'workflow', hash('sha256', $target));
            return $receipt;
        });
    }
}
