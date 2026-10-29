<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;

/** Runtime and full both validate executable definitions through the same business contract. */
final readonly class WorkflowDefinitionValidator
{
    public function __construct(private WorkflowScenarioProvider $scenarios, private ?\PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher $tasks, private \PeanutAdmin\Kernel\Module\ModuleAvailability $modules, private ?\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry $producers = null) {}

    public function validate(AuthorizedOperationContext $context, string $module, string $workflow, WorkflowGraph $graph): void
    {
        $scenario = $this->scenarios->scenario($context, $module, $workflow);
        if ($scenario['contract_version'] !== $graph->data['scenario_contract_version']) {
            throw WorkflowException::unsupported('scenario_contract_version');
        }
        foreach ($graph->data['required_capabilities'] as $capability) {
            if (!in_array($capability, $scenario['capabilities'], true)) {
                throw WorkflowException::unsupported($capability);
            }
        }
        if (in_array('workflow.service_task', $graph->data['required_capabilities'], true)) {
            if ($this->tasks === null) {
                throw WorkflowException::unsupported('workflow.service_task');
            }
            try {
                $this->modules->assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($context->tenantContext->tenantId, 'workflow'), 'peanut.task', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), true);
            } catch (\PeanutAdmin\Kernel\Module\ModuleException) {
                throw WorkflowException::unsupported('workflow.service_task');
            }
        }
        if (in_array('workflow.notification', $graph->data['required_capabilities'], true)) {
            if ($this->producers === null || $this->tasks === null || !is_string($scenario['notification']['producer_key'] ?? null)) { throw WorkflowException::unsupported('workflow.notification'); }
            try {
                foreach (['peanut.notification', 'peanut.task'] as $moduleKey) {
                    $this->modules->assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($context->tenantContext->tenantId, 'workflow'), $moduleKey, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), true);
                }
                $producer = $this->producers->require($scenario['notification']['producer_key']);
                if ($producer->resourceKey() !== $graph->data['subject_resource_key']) { throw WorkflowException::unsupported('workflow.notification.producer'); }
                $this->tasks->assertRegistered($producer->taskType(), $producer->resourceKey(), $producer->operation());
            } catch (\PeanutAdmin\Kernel\Module\ModuleException|\PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException|\PeanutAdmin\Modules\Task\Job\Application\TaskJobException) {
                throw WorkflowException::unsupported('workflow.notification.provider');
            }
        }
        foreach ($graph->data['nodes'] as $node) {
            if ($node['type'] === 'end' && !in_array($node['config']['result_code'], $scenario['result_codes'], true)) {
                throw WorkflowException::definitionInvalid('Unregistered result code.');
            }
            if ($node['type'] === 'exclusive_gateway') {
                foreach ($node['config']['branches'] as $branch) {
                    \PeanutAdmin\Modules\Workflow\Definition\WorkflowCondition::registered($branch['condition'], $scenario['fields']);
                }
            }
            if ($node['type'] === 'service_task') {
                $action = $scenario['actions'][$node['config']['action_key']] ?? throw WorkflowException::unsupported('action:' . $node['config']['action_key']);
                if ($action['contract_version'] !== $node['config']['contract_version']) {
                    throw WorkflowException::unsupported('action_contract_version');
                }
                try {
                    $this->tasks->assertRegistered($action['task_type'], $graph->data['subject_resource_key'], $action['operation']);
                } catch (\PeanutAdmin\Modules\Task\Job\Application\TaskJobException) {
                    throw WorkflowException::unsupported('nodes.' . $node['key'] . '.task_type');
                }
                \PeanutAdmin\Modules\Workflow\Definition\WorkflowActionInput::validate($node['config']['input'], $scenario['fields']);
                if (array_diff($action['input_schema']['required'] ?? [], array_keys($node['config']['input']))) {
                    throw WorkflowException::definitionInvalid('Required business action input mapping is missing.');
                }
            }
        }
    }
}
