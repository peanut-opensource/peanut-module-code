<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Integration;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

/** Source-code-only registration. Missing or conflicting business capability fails closed. */
final class WorkflowScenarioRegistry implements WorkflowScenarioProvider
{
    private array $scenes = [];
    private array $resources = [];
    private array $actions = [];

    public function register(string $moduleKey, string $workflowKey, string $resourceKey, WorkflowScenarioProvider $provider, WorkflowScenarioAccess $access, array $actionKeys = []): void
    {
        $key = $moduleKey . ':' . $workflowKey;
        $resource = $this->resources[$resourceKey] ?? null;
        if ($moduleKey === '' || $workflowKey === '' || $resourceKey === '' || isset($this->scenes[$key])
            || ($resource !== null && ($resource['module_key'] !== $moduleKey
                || $resource['provider'] !== $provider || $resource['access'] !== $access))) {
            throw new \LogicException('WORKFLOW_SCENARIO_REGISTRATION_CONFLICT');
        }
        foreach ($actionKeys as $action) {
            if (!is_string($action) || $action === '' || isset($this->actions[$action])) {
                throw new \LogicException('WORKFLOW_ACTION_REGISTRATION_CONFLICT');
            }
        }
        $entry = ['module_key' => $moduleKey, 'workflow_key' => $workflowKey, 'resource_key' => $resourceKey, 'provider' => $provider, 'access' => $access, 'action_keys' => $actionKeys];
        $this->scenes[$key] = $entry;
        $this->resources[$resourceKey] ??= $entry;
        foreach ($actionKeys as $action) {
            $this->actions[$action] = $entry;
        }
    }

    public function registrations(): array
    {
        return array_values($this->scenes);
    }

    public function access(string $resourceKey): WorkflowScenarioAccess
    {
        return $this->resource($resourceKey)['access'];
    }

    public function scenario(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey): array
    {
        $entry = $this->scene($moduleKey, $workflowKey);
        $metadata = $entry['provider']->scenario($context, $moduleKey, $workflowKey);
        if (!is_int($metadata['contract_version'] ?? null) || $metadata['contract_version'] < 1
            || !is_array($metadata['fields'] ?? null) || !is_array($metadata['actions'] ?? null)
            || array_diff(array_keys($metadata['actions']), $entry['action_keys']) !== []) {
            throw WorkflowException::definitionInvalid('WORKFLOW_SCENARIO_CONTRACT_INVALID');
        }
        foreach ($metadata['actions'] as $key => $action) {
            if (($this->actions[$key] ?? null) !== $entry || !is_array($action['input_schema'] ?? null)) {
                throw WorkflowException::definitionInvalid('WORKFLOW_ACTION_CONTRACT_INVALID');
            }
        }
        return $metadata;
    }

    public function businessDepartment(AuthorizedOperationContext $context, ?string $requestedKey): ?string
    {
        return $this->resource($context->resourceKey)['provider']->businessDepartment($context, $requestedKey);
    }

    public function subjectOwner(AuthorizedOperationContext $context, string $subjectType, string $subjectKey): ?int
    {
        return $this->resource($subjectType)['provider']->subjectOwner($context, $subjectType, $subjectKey);
    }

    public function prepareSubjectSnapshot(AuthorizedOperationContext $context, string $subjectType, string $subjectKey, string $revisionKey): object
    {
        return $this->resource($subjectType)['provider']->prepareSubjectSnapshot($context, $subjectType, $subjectKey, $revisionKey);
    }

    public function snapshot(AuthorizedOperationContext $context, string $subjectType, string $subjectKey, string $revisionKey, bool $forUpdate, object $prepared): array
    {
        return $this->resource($subjectType)['provider']->snapshot($context, $subjectType, $subjectKey, $revisionKey, $forUpdate, $prepared);
    }

    public function validateCompletion(AuthorizedOperationContext $context, array $instance, array $input): array
    {
        return $this->resource((string) ($instance['subject_type'] ?? ''))['provider']->validateCompletion($context, $instance, $input);
    }

    public function executeAction(AuthorizedOperationContext $context, string $actionKey, int $contractVersion, array $input, string $effectKey): array
    {
        $entry = $this->actions[$actionKey] ?? throw WorkflowException::definitionInvalid('WORKFLOW_ACTION_UNREGISTERED');
        if ($context->resourceKey !== $entry['resource_key']) {
            throw WorkflowException::assignmentDenied();
        }
        return $entry['provider']->executeAction($context, $actionKey, $contractVersion, $input, $effectKey);
    }

    private function scene(string $moduleKey, string $workflowKey): array
    {
        return $this->scenes[$moduleKey . ':' . $workflowKey] ?? throw WorkflowException::definitionInvalid('WORKFLOW_SCENARIO_UNREGISTERED');
    }

    private function resource(string $resourceKey): array
    {
        return $this->resources[$resourceKey] ?? throw WorkflowException::definitionInvalid('WORKFLOW_SUBJECT_PROVIDER_UNREGISTERED');
    }
}
