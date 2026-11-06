<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Contract\TaskSubmission;
use PeanutAdmin\Modules\Task\Contract\JobExecution;

final readonly class AiNativeTask implements TaskSubmissionProvider, TaskWorkerDefinition, TaskHandler
{
    public function __construct(private AiTaskAuthorization $authorization, private AiExecution $execution) {}
    public function ownerModuleKey(): string
    {
        return 'custom.ai-assistant';
    }
    public function taskType(): string
    {
        return 'custom.ai-assistant.generate';
    }
    public function resourceKey(): string
    {
        return 'custom.ai-assistant.task';
    }
    public function operation(): string
    {
        return 'use';
    }
    public function key(): string
    {
        return 'custom.ai-assistant.generate';
    }
    public function handlers(): array
    {
        return [$this,app()->make(AiManualTask::class)];
    }
    public function reauthorize(VerifiedJobEnvelope $e): AuthorizedOperationContext
    {
        return $this->authorization->reauthorize($e);
    }
    public function build(AuthorizedOperationContext $c, array $input): TaskSubmission
    {
        if (array_keys($input) !== ['task_id'] || !is_int($input['task_id']) || $input['task_id'] < 1) {
            throw new \InvalidArgumentException('AI_TASK_INPUT_INVALID');
        }
        $this->authorization->authorize($c->tenantContext, $input['task_id']);
        return new TaskSubmission($this->key(), $input, 1);
    }
    public function handle(AuthorizedOperationContext $c, JobExecution $e): void
    {
        $e->checkpoint();
        $id = $e->payload['task_id'] ?? null;
        if (!is_int($id) || $c->resourceKey !== $this->resourceKey() || $c->operation !== 'use') {
            throw new \DomainException('AI_TASK_CONTEXT_INVALID');
        }$this->execution->execute($c, $e, $id);
    }
}
