<?php

declare(strict_types=1);

namespace app\common\infrastructure\async;

use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Module\ModuleException;

/** Rechecks Task and owning Module authorization at every worker fence. */
final readonly class ModuleAwareTaskHandler implements TaskHandler, \PeanutAdmin\Modules\Task\Contract\NegativeFactTaskHandler
{
    public function negativeSubmission(array $fact): \PeanutAdmin\Modules\Task\Contract\TaskSubmission
    {
        if (!$this->inner instanceof \PeanutAdmin\Modules\Task\Contract\NegativeFactTaskHandler) {
            throw TaskJobException::denied();
        }
        return $this->inner->negativeSubmission($fact);
    }

    public function validateNegativeFact(\PeanutAdmin\Modules\Task\Job\Execution\NegativeFactJobEnvelope $envelope): void
    {
        if (!$this->inner instanceof \PeanutAdmin\Modules\Task\Contract\NegativeFactTaskHandler
            || $this->moduleKey !== 'peanut.collaboration' || $this->key() !== 'identity.revocation.propagate') {
            throw TaskJobException::denied();
        }
        $ambient = $this->executionContexts->require();
        if (!$ambient instanceof SystemExecutionContext || $ambient->tenantId() !== $envelope->tenantId
            || $ambient->operation() !== 'identity.revocation.propagate' || $ambient->metadata->jobKey !== $envelope->jobKey
            || $ambient->metadata->handlerKey !== $this->key() || $this->executionContexts->rootIdentity() === null) {
            throw TaskJobException::denied();
        }
        $this->modules->assertWorker('peanut.task');
        $this->modules->assertWorker($this->moduleKey);
        $this->inner->validateNegativeFact($envelope);
    }

    public function handleNegativeFact(\PeanutAdmin\Modules\Task\Job\Execution\NegativeFactJobEnvelope $envelope, JobExecution $execution): void
    {
        $execution->addAuthorizationCheck(function () use ($envelope, $execution): void {
            $this->validateNegativeFact($envelope);
            $ambient = $this->executionContexts->require();
            if (!$ambient instanceof SystemExecutionContext || $ambient->metadata->attemptNumber !== $execution->attemptNumber) {
                throw TaskJobException::denied();
            }
        });
        $execution->checkpoint();
        $this->inner->handleNegativeFact($envelope, $execution);
        $execution->assertLeaseOwned();
    }
    public function __construct(
        private ModuleExecutionBoundary $modules,
        private ExecutionContextStore $executionContexts,
        private string $moduleKey,
        private TaskHandler $inner,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,127}$/D', trim($moduleKey)) !== 1) {
            throw new \InvalidArgumentException('MODULE_CONTEXT_INVALID');
        }
    }

    public function key(): string
    {
        return $this->inner->key();
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        $execution->addAuthorizationCheck(function () use ($context, $execution): void {
            try {
                $ambient = $this->executionContexts->require();
                if (!$ambient instanceof SystemExecutionContext || $ambient->tenantId() !== $context->tenantContext->tenantId
                    || $ambient->metadata->jobKey !== $execution->jobKey || $ambient->metadata->attemptNumber !== $execution->attemptNumber
                    || $ambient->metadata->handlerKey !== $this->key() || $this->executionContexts->rootIdentity() === null) {
                    throw TaskJobException::denied();
                }
                $this->modules->assertWorker('peanut.task');
                $this->modules->assertWorker($this->moduleKey);
            } catch (AuthException|ModuleException|\DomainException) {
                throw TaskJobException::denied();
            }
        });
        $execution->checkpoint();
        $this->inner->handle($context, $execution);
        $execution->assertLeaseOwned();
    }
}
