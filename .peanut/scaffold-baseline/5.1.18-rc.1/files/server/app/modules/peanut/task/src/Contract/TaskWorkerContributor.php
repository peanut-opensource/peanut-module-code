<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

/** Optional ModuleProvider capability for contributing handlers to the compiled Tenant worker. */
interface TaskWorkerContributor
{
    /** @return list<class-string<TaskWorkerDefinition>> */
    public function taskWorkerDefinitions(): array;
}
