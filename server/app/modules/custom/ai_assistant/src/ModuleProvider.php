<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\DataPermission\Runtime\{DataPermissionModuleProvider,DataPermissionRuntimeRegistry};
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerContributor;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerContributor;
use Custom\Modules\AiAssistant\Service\{AiScopeProvider,AiNativeTask,AiDocumentOwner};

final class ModuleProvider implements ModuleProviderContract, DataPermissionModuleProvider, DocumentOwnerContributor, TaskWorkerContributor
{
    public function moduleKey(): string
    {
        return 'custom.ai-assistant';
    }
    public function bindings(): array
    {
        return [];
    }
    public function registerDataPermission(DataPermissionRuntimeRegistry $registry): void
    {
        $registry->registerResourceProvider(AiScopeProvider::class, app()->make(AiScopeProvider::class));
    }
    public function documentOwnerPolicies(): array
    {
        return ['task' => AiDocumentOwner::class];
    }
    public function taskWorkerDefinitions(): array
    {
        return [AiNativeTask::class];
    }
}
