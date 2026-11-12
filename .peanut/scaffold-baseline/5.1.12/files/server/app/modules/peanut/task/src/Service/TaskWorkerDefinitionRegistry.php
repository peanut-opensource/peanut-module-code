<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use think\App;

/** Compiled worker contributions from the installed Module providers. */
final readonly class TaskWorkerDefinitionRegistry
{
    /** @param list<array{module_key:string,definition:class-string<TaskWorkerDefinition>}> $registrations */
    public function __construct(private array $registrations)
    {
        $seen = [];
        foreach ($registrations as $registration) {
            $moduleKey = $registration['module_key'] ?? '';
            $definition = $registration['definition'] ?? '';
            if (!is_string($moduleKey) || preg_match('/^[a-z0-9][a-z0-9._-]{1,127}$/D', $moduleKey) !== 1
                || !is_string($definition) || !is_a($definition, TaskWorkerDefinition::class, true)
                || isset($seen[$definition])
            ) {
                throw new ModuleException('MODULE_TASK_WORKER_INVALID', 'Module Task worker contribution is invalid.');
            }
            $seen[$definition] = true;
        }
    }

    /** @return list<TaskWorkerDefinition> */
    public function resolve(App $app): array
    {
        $definitions = [];
        $authorizationKeys = [];
        $handlerKeys = [];
        foreach ($this->registrations as $registration) {
            $definition = $app->make($registration['definition']);
            if (!$definition instanceof TaskWorkerDefinition
                || !hash_equals($registration['module_key'], $definition->ownerModuleKey())
            ) {
                throw new ModuleException('MODULE_TASK_WORKER_INVALID', 'Module Task worker owner is invalid.');
            }
            $authorizationKey = $definition->resourceKey() . "\0" . $definition->operation();
            if (isset($authorizationKeys[$authorizationKey])) {
                throw new ModuleException('MODULE_TASK_WORKER_CONFLICT', 'Module Task authorization contribution is duplicated.');
            }
            $authorizationKeys[$authorizationKey] = true;
            $handlerCount = 0;
            foreach ($definition->handlers() as $handler) {
                if (!$handler instanceof TaskHandler || isset($handlerKeys[$handler->key()])) {
                    throw new ModuleException('MODULE_TASK_WORKER_CONFLICT', 'Module Task handler contribution is invalid or duplicated.');
                }
                $handlerKeys[$handler->key()] = true;
                ++$handlerCount;
            }
            if ($handlerCount === 0) {
                throw new ModuleException('MODULE_TASK_WORKER_INVALID', 'Module Task worker has no handlers.');
            }
            $definitions[] = $definition;
        }

        return $definitions;
    }
}
