<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

/** 官方维护的可选业务模块；注册定义不启用租户、不执行迁移或读取当前身份。 */
final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.workflow';
    }

    public function bindings(): array
    {
        return [
            \PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionValidator::class => fn(\think\App $app) => new \PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionValidator($app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider::class), $app->bound(\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher::class) ? $app->make(\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher::class) : null, $app->make(\PeanutAdmin\Kernel\Module\ModuleAvailability::class), $app->bound(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class) ? $app->make(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class) : null),
            \PeanutAdmin\Modules\Workflow\Application\WorkflowNotificationDelivery::class => fn(\think\App $app) => new \PeanutAdmin\Modules\Workflow\Application\WorkflowNotificationDelivery(
                $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries::class), $app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowCommandTransaction::class), $app->make(\PeanutAdmin\Modules\Identity\Audit\AuditService::class),
                $app->bound(\PeanutAdmin\Modules\Notification\Delivery\Application\NotificationService::class) ? $app->make(\PeanutAdmin\Modules\Notification\Delivery\Application\NotificationService::class) : null,
                $app->bound(\PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class) ? $app->make(\PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class)->jobs() : null,
            ),
            \PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider::class => \PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry::class,
            \PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver::class => \PeanutAdmin\Modules\Workflow\Integration\IdentityWorkflowAssignmentResolver::class,
            \PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver::class => \PeanutAdmin\Modules\Workflow\Integration\ScenarioWorkflowAuthorizationResolver::class,
            \PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionService::class => fn(\think\App $app) => new \PeanutAdmin\Modules\Workflow\Application\WorkflowDefinitionService(
                $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowCommandTransaction::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver::class),
                $app->make(\PeanutAdmin\Modules\Identity\Audit\AuditService::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries::class),
                $app->make(\PeanutAdmin\Kernel\Module\ModuleAvailability::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver::class),
                $app->bound(\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher::class) ? $app->make(\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher::class) : null,
                $app->bound(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class) ? $app->make(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class) : null,
            ),
            \PeanutAdmin\Modules\Workflow\Application\WorkflowCommands::class => fn(\think\App $app) => new \PeanutAdmin\Modules\Workflow\Application\WorkflowCommands(
                $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowCommandTransaction::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowQueries::class),
                $app->make(\PeanutAdmin\Modules\Identity\Audit\AuditService::class),
                $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries::class),
                $app->bound(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachmentResolver::class) ? $app->make(\PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachmentResolver::class) : null,
                $app->bound(\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher::class) ? $app->make(\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher::class) : null,
                $app->bound(\PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class) ? $app->make(\PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class)->jobs() : null,
            ),
        ];
    }
}
