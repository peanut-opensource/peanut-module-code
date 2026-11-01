<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use app\common\enum\CrontabEnum;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\execution\SystemExecutionMetadata;
use app\common\services\CrontabCommandService;
use PeanutAdmin\Modules\Task\Model\Crontab;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use DateTimeImmutable;
use DateTimeZone;
use Closure;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Contract\TaskSubmission;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;

/** The built-in Task definition for one claimed Crontab schedule window. */
final class CrontabTaskDefinition implements TaskSubmissionProvider, TaskWorkerDefinition, TaskHandler
{
    public const TASK_TYPE = 'peanut.task.crontab';
    private const RESOURCE_KEY = 'peanut.task.crontab';
    private const OPERATION = 'execute';
    private const HANDLER_KEY = 'peanut.task.crontab.execute';

    public function __construct(
        private readonly AdminDirectoryQuery $adminDirectory,
        private readonly ModuleExecutionBoundary $modules,
        private readonly ExecutionContextStore $executionContexts,
        private readonly CurrentExecutionContext $currentExecution,
        private readonly CrontabCommandService $commands,
        private readonly Closure $dispatch,
    ) {}

    public function submissionContext(TenantScope $scope, string $traceId): AuthorizedOperationContext
    {
        return $this->authorizedContext($scope->tenantId(), null, null, $traceId);
    }

    public function taskType(): string
    {
        return self::TASK_TYPE;
    }

    public function resourceKey(): string
    {
        return self::RESOURCE_KEY;
    }

    public function operation(): string
    {
        return self::OPERATION;
    }

    public static function contextIdentity(int $tenantId, int $scheduleId, int $window): string
    {
        if ($tenantId < 1 || $scheduleId < 1 || $window < 0) {
            throw new \InvalidArgumentException('CRONTAB_TASK_CONTEXT_INVALID');
        }
        return sprintf('crontab:v1:tenant=%d:job=%d:window=%d', $tenantId, $scheduleId, $window);
    }

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        $scheduleId = self::positiveInt($input['schedule_id'] ?? null, 'CRONTAB_TASK_INVALID');
        $contextIdentity = trim((string) ($input['context_identity'] ?? ''));
        self::assertContextIdentity($contextIdentity, $context->tenantContext->tenantId, $scheduleId);

        return new TaskSubmission(self::HANDLER_KEY, [
            'schedule_id' => $scheduleId,
            'context_identity' => $contextIdentity,
        ]);
    }

    public function ownerModuleKey(): string
    {
        return 'peanut.task';
    }

    public function handlers(): array
    {
        return [$this];
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        if (!hash_equals(self::RESOURCE_KEY, $envelope->resourceKey)
            || !hash_equals(self::OPERATION, $envelope->operation)
            || $envelope->requestedTargets !== []
        ) {
            throw new \runtimeException('CRONTAB_TASK_AUTHORIZATION_INVALID');
        }

        return $this->authorizedContext(
            $envelope->tenantId,
            $envelope->memberId,
            $envelope->accountId,
            $envelope->traceId,
        );
    }

    public function key(): string
    {
        return self::HANDLER_KEY;
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        $execution->checkpoint();
        $scheduleId = self::positiveInt($execution->payload['schedule_id'] ?? null, 'CRONTAB_TASK_INVALID');
        $contextIdentity = trim((string) ($execution->payload['context_identity'] ?? ''));
        self::assertContextIdentity($contextIdentity, $context->tenantContext->tenantId, $scheduleId);
        $scope = TenantScope::fromTrustedContext($context->tenantContext->tenantId, $contextIdentity);
        $item = Crontab::find($scheduleId);
        if ($item === null || (int) $item->status !== CrontabEnum::START) {
            return;
        }

        $command = trim((string) $item->command);
        $this->commands->assertTenantAware($command);
        $moduleKey = $this->commands->moduleKey($command)
            ?? throw new \runtimeException('CRONTAB_MODULE_UNAVAILABLE');
        $params = ((string) $item->params !== '') ? explode(' ', (string) $item->params) : [];
        $system = new TenantSystemContext(
            $scope->tenantId(),
            'scheduler',
            'crontab.execute',
            $scope->contextIdentity(),
        );

        $task = $this->currentExecution->systemExecution()->metadata;
        $this->executionContexts->run(new SystemExecutionContext(
            $system,
            new SystemExecutionMetadata($task->jobKey, $task->attemptNumber, $task->handlerKey),
        ), function () use ($scope, $moduleKey, $command, $params): void {
            $this->modules->assertScheduled('peanut.task');
            $this->modules->assertScheduled($moduleKey);
            ScheduledTenantContext::run($scope, function () use ($command, $params): void {
                try {
                    ($this->dispatch)($command, $params);
                } catch (\Throwable) {
                    throw new RetryableTaskException('CRONTAB_EXECUTION_FAILED');
                }
            });
        });
        $execution->assertLeaseOwned();
    }

    private function authorizedContext(
        int $tenantId,
        ?int $memberId,
        ?int $accountId,
        string $traceId,
    ): AuthorizedOperationContext {
        if ($tenantId < 1 || trim($traceId) === '') {
            throw new \runtimeException('CRONTAB_TASK_AUTHORIZATION_INVALID');
        }
        if ($memberId !== null || $accountId !== null) {
            if (($memberId ?? 0) < 1 || ($accountId ?? 0) < 1) {
                throw new \runtimeException('CRONTAB_TASK_AUTHORIZATION_INVALID');
            }
        }
        $owner = $this->adminDirectory->activeTenantOwner($tenantId, $memberId, $accountId);
        if ($owner === null) {
            throw new \runtimeException('CRONTAB_TENANT_OWNER_UNAVAILABLE');
        }
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            (int) $owner['id'],
            'crontab-' . hash('sha256', $traceId),
            $tenantId,
            (int) $owner['account_id'],
            (int) $owner['id'],
            'task-worker',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            (int) $owner['authorization_revision'],
            (int) $owner['tenant_authorization_revision'],
        ), $traceId);

        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            self::RESOURCE_KEY,
            self::OPERATION,
            [],
            hash('sha256', implode("\0", [
                (string) $tenantId,
                (string) $tenant->memberId,
                (string) $tenant->authorizationRevision,
                self::RESOURCE_KEY,
                self::OPERATION,
            ])),
        ));
    }

    private static function positiveInt(mixed $value, string $message): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException($message);
        }
        $value = (int) $value;
        if ($value < 1) {
            throw new \InvalidArgumentException($message);
        }
        return $value;
    }

    private static function assertContextIdentity(string $identity, int $tenantId, int $scheduleId): void
    {
        if (preg_match('/^crontab:v1:tenant=([1-9][0-9]*):job=([1-9][0-9]*):window=[0-9]+$/D', $identity, $matches) !== 1
            || (int) $matches[1] !== $tenantId
            || (int) $matches[2] !== $scheduleId
        ) {
            throw new \InvalidArgumentException('CRONTAB_TASK_CONTEXT_INVALID');
        }
    }
}
