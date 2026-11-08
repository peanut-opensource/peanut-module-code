<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Infrastructure\Runtime;

use PeanutAdmin\Modules\Task\Service\CrontabTaskDefinition;
use PeanutAdmin\Modules\Task\Service\TaskAuthorizationRouter;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use app\common\infrastructure\async\ModuleAwareTaskHandler;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use app\common\services\CrontabCommandService;
use Closure;
use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Modules\Task\Contract\TaskJobService;
use PeanutAdmin\Modules\Task\Job\Execution\LocalWorker;
use PeanutAdmin\Modules\Task\Job\Execution\TaskHandlerRegistry;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;
use PeanutAdmin\Modules\Task\Job\Submission\TaskSubmissionRegistry;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Task\Job\Execution\JobClaim;
use app\common\execution\SystemExecutionContext;
use app\common\execution\SystemExecutionMetadata;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

final readonly class ThinkPhpTaskJobRuntime implements TaskJobRuntime, \PeanutAdmin\Modules\Task\Contract\TaskSourceAuthorizations
{
    /** Only LocalWorker-issued executions are registered, for the exact running System root. */
    private \WeakMap $sourceExecutions;
    /** @param list<TaskWorkerDefinition> $workerDefinitions */
    public function __construct(
        private TaskJobStore $repository,
        private string $signingKey,
        private ExecutionContextStore $executionContexts,
        private CurrentExecutionContext $currentExecution,
        private AdminDirectoryQuery $adminDirectory,
        private ModuleExecutionBoundary $modules,
        private ModuleGuard $moduleGuard,
        private CrontabCommandService $commands,
        private Closure $dispatch,
        private array $workerDefinitions,
        private int $workerLimit,
        private ResourceOperationCatalog $operations,
        private TenantEntitlementService $entitlements,
    ) {
        $this->sourceExecutions = new \WeakMap();
        if (strlen($this->signingKey) < 32) {
            throw new \runtimeException('ASYNC_SIGNING_KEY_INVALID');
        }
    }

    public function publisher(TaskSubmissionProvider ...$providers): TrustedJobPublisher
    {
        return new TrustedJobPublisher(
            $this->repository,
            new TaskSubmissionRegistry($providers),
            $this->envelopes(),
            $this->operations,
            $this->entitlements,
            $this->signingKey,
        );
    }

    public function jobs(): TaskJobService
    {
        return new TaskJobService($this->repository);
    }

    public function enqueueCrontab(TenantScope $scope, int $scheduleId, string $contextIdentity): void
    {
        $definition = $this->crontabs();
        $this->publisher($definition)->publish(
            $definition->submissionContext($scope, $contextIdentity),
            CrontabTaskDefinition::TASK_TYPE,
            ['schedule_id' => $scheduleId, 'context_identity' => $contextIdentity],
            'crontab:' . hash('sha256', $contextIdentity),
        );
    }

    public function runTenant(int $tenantId, string $workerId, ?string $jobKey = null): int
    {
        if (!$this->executionContexts->isEmpty()) {
            throw new \DomainException('TASK_EXECUTION_ROOT_REQUIRED');
        }
        if ($tenantId < 1) {
            throw new \runtimeException('ASYNC_TENANT_INVALID');
        }

        $definitions = [...$this->workerDefinitions, $this->crontabs()];
        $handlers = [];
        foreach ($definitions as $definition) {
            foreach ($definition->handlers() as $handler) {
                $handlers[] = new ModuleAwareTaskHandler(
                    $this->modules,
                    $this->executionContexts,
                    $definition->ownerModuleKey(),
                    $handler,
                );
            }
        }
        $worker = new LocalWorker(
            $tenantId,
            $workerId,
            $this->repository,
            new TaskHandlerRegistry($handlers),
            new JobHandlerAdapter($this->envelopes(), new TaskAuthorizationRouter($definitions)),
            function (AuthorizedOperationContext|\PeanutAdmin\Modules\Task\Job\Execution\NegativeFactJobEnvelope $context, JobClaim $claim, Closure $handle, ?\PeanutAdmin\Modules\Task\Contract\JobExecution $execution = null): void {
                $negative = $context instanceof \PeanutAdmin\Modules\Task\Job\Execution\NegativeFactJobEnvelope;
                if (!$this->executionContexts->isEmpty() || ($negative ? $context->tenantId : $context->tenantContext->tenantId) !== $claim->tenantId) {
                    throw new \DomainException('TASK_EXECUTION_ROOT_REQUIRED');
                }
                $root = new SystemExecutionContext(
                    new TenantSystemContext($claim->tenantId, 'task-worker', $negative ? 'identity.revocation.propagate' : 'async.worker', $claim->jobKey),
                    new SystemExecutionMetadata($claim->jobKey, $claim->attemptNumber, $claim->handlerKey),
                );
                $this->executionContexts->run($root, function () use ($root, $claim, $handle, $execution, $negative): void {
                    if (!$negative && $execution !== null) {
                        $this->sourceExecutions[$execution] = ['root' => $root, 'claim' => $claim];
                    }
                    try {
                        $handle();
                    } finally {
                        if ($execution !== null) {
                            unset($this->sourceExecutions[$execution]);
                        }
                    }
                });
            },
            negativeSigningKey: $this->signingKey,
            jobKey: $jobKey,
        );

        $processed = 0;
        $limit = min(1000, max(1, $this->workerLimit));
        while ($processed < $limit && $worker->runOnce() !== null) {
            ++$processed;
        }
        return $processed;
    }


    public function assertSourceSubmission(\PeanutAdmin\Kernel\Auth\TenantContext $issuer, \PeanutAdmin\Modules\Task\Contract\SourceTaskBinding $binding): void
    {
        $actor = $this->currentExecution->tenantAdmin();
        if ($actor->tenantId !== $issuer->tenantId || $actor->accountId !== $issuer->accountId || $actor->memberId !== $issuer->memberId
            || $actor->clientKey !== $issuer->clientKey || !hash_equals($actor->sessionKey, $issuer->sessionKey)
            || !\think\facade\Db::connect()->getPdo()->inTransaction()) {
            throw new \DomainException('TASK_SOURCE_SUBMISSION_DENIED');
        }
        $this->assertSourceDefinition($binding);
        $row = $this->repository->sourceSubmission($issuer->tenantId, $binding->jobKey);
        if ($row['handler_key'] !== $binding->handlerKey || (int) $row['created_by_member_id'] !== $issuer->memberId) {
            throw new \DomainException('TASK_SOURCE_SUBMISSION_DENIED');
        }
        $adapter = new JobHandlerAdapter($this->envelopes(), new TaskAuthorizationRouter($this->workerDefinitions));
        $adapter->handle($row['trusted_envelope'], function (AuthorizedOperationContext $context, \PeanutAdmin\Kernel\Async\VerifiedJobEnvelope $envelope) use ($issuer, $binding): void {
            if ($envelope->tenantId !== $issuer->tenantId || $envelope->accountId !== $issuer->accountId || $envelope->memberId !== $issuer->memberId
                || $envelope->operationId !== $binding->jobKey || $envelope->resourceKey !== $binding->resourceKey || $envelope->operation !== $binding->operation
                || $context->tenantContext->tenantId !== $issuer->tenantId || $context->tenantContext->accountId !== $issuer->accountId
                || $context->tenantContext->memberId !== $issuer->memberId || $context->tenantContext->authorizationRevision !== $issuer->authorizationRevision
                || $context->tenantContext->clientKey !== $issuer->clientKey || !hash_equals($context->tenantContext->sessionKey, $issuer->sessionKey)
                || $context->resourceKey !== $binding->resourceKey || $context->operation !== $binding->operation
                || self::sourceTargets($context->targets) !== self::sourceTargets($envelope->requestedTargets)) {
                throw new \DomainException('TASK_SOURCE_SUBMISSION_DENIED');
            }
        });
    }

    public function sourceActor(\PeanutAdmin\Modules\Task\Contract\JobExecution $execution, \PeanutAdmin\Modules\Task\Contract\SourceTaskBinding $binding): \PeanutAdmin\Kernel\Auth\TenantContext
    {
        $active = $this->sourceExecutions[$execution] ?? null;
        $root = $this->executionContexts->current();
        if ($active === null || $active['root'] !== $root || !$root instanceof SystemExecutionContext
            || $root->system->actorKey !== 'task-worker' || $root->system->operation !== 'async.worker'
            || $root->metadata->jobKey !== $execution->jobKey || $root->system->operationId !== $execution->jobKey
            || $root->metadata->attemptNumber !== $execution->attemptNumber || $root->tenantId() !== $execution->tenantId
            || $root->metadata->handlerKey !== $binding->handlerKey || $execution->jobKey !== $binding->jobKey) {
            throw new \DomainException('TASK_SOURCE_EXECUTION_DENIED');
        }
        $this->assertSourceDefinition($binding);
        $claim = $active['claim'];
        $this->repository->assertExecutable($claim);
        $fence = new \PeanutAdmin\Modules\Task\Job\Execution\JobAuthorizationFence(
            new JobHandlerAdapter($this->envelopes(), new TaskAuthorizationRouter($this->workerDefinitions)),
            $claim,
        );
        // Do not recurse through JobExecution's additional source checks: this IS its native fence.
        return $fence->handle(function (AuthorizedOperationContext $context) use ($binding): \PeanutAdmin\Kernel\Auth\TenantContext {
            if ($context->resourceKey !== $binding->resourceKey || $context->operation !== $binding->operation) {
                throw new \DomainException('TASK_SOURCE_EXECUTION_DENIED');
            }
            return $context->tenantContext;
        });
    }

    private function assertSourceDefinition(\PeanutAdmin\Modules\Task\Contract\SourceTaskBinding $binding): void
    {
        foreach ($this->workerDefinitions as $definition) {
            if ($definition->ownerModuleKey() === $binding->moduleKey && $definition->resourceKey() === $binding->resourceKey
                && $definition->operation() === $binding->operation) {
                foreach ($definition->handlers() as $handler) {
                    if ($handler->key() === $binding->handlerKey) {
                        return;
                    }
                }
            }
        }
        throw new \DomainException('TASK_SOURCE_CONSUMER_DENIED');
    }

    private static function sourceTargets(array $targets): array
    {
        $values = array_map(static fn($target): array => $target->toArray(), $targets);
        usort($values, static fn(array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
        return $values;
    }

    private function envelopes(): TrustedEnvelopeCodec
    {
        return new TrustedEnvelopeCodec($this->signingKey);
    }

    private function crontabs(): CrontabTaskDefinition
    {
        return new CrontabTaskDefinition(
            $this->adminDirectory,
            $this->modules,
            $this->moduleGuard,
            $this->executionContexts,
            $this->currentExecution,
            $this->commands,
            $this->dispatch,
        );
    }
}
