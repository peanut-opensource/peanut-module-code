<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Integration;

/** Optional selected business provider contribution, registered by the host composition root. */
interface WorkflowScenarioContributor
{
    public function registerWorkflowScenarios(WorkflowScenarioRegistry $registry, \think\App $app): void;

    /** @return list<\PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider> */
    public function workflowTaskSubmissions(\think\App $app): array;
}
