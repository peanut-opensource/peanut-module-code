<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Workflow\Application\WorkflowCommands;

/** Registered in the official Task handler registry by the compiled Module contribution. */
final readonly class WorkflowServiceTaskHandler implements TaskHandler
{
    public function __construct(private WorkflowCommands $commands) {}
    public function key(): string { return 'workflow.service-action'; }
    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        $this->commands->executeServiceTask($context, $execution);
    }
}
