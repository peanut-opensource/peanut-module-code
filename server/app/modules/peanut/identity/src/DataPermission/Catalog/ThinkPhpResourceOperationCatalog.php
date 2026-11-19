<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Catalog;

use PeanutAdmin\DataPermission\Catalog\OperationTargetType;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

final readonly class ThinkPhpResourceOperationCatalog implements ResourceOperationCatalog
{
    /** @var array<string, array<string, ResourceOperation>> */
    private array $operations;
    /** @var array<int, ResourceOperation> */
    private array $operationIds;
    /** @var array<int, array<string, list<string|null>>> */
    private array $conditions;
    /** @var array<string, array<string, string>> */
    private array $targets;

    public function __construct(
        private CompiledModuleRegistry $registry,
        private TenantEntitlementService $entitlements,
    ) {
        $targets = [];
        foreach ($registry->modules as $manifest) {
            foreach ($manifest->data['catalog']['target_types'] ?? [] as $target) {
                $targets[$target['key']] = $target + ['module_key' => $manifest->data['key']];
            }
        }

        $operations = [];
        $operationIds = [];
        $conditions = [];
        $resourceId = 0;
        foreach ($registry->modules as $manifest) {
            foreach ($manifest->data['catalog']['protected_resources'] ?? [] as $resource) {
                ++$resourceId;
                foreach ($resource['operations'] as $definition) {
                    $id = count($operationIds) + 1;
                    $operation = new ResourceOperation(
                        $id,
                        $resourceId,
                        $resource['key'],
                        $manifest->data['key'],
                        $resource['provider'],
                        $resource['ownership'],
                        $definition['key'],
                        $definition['access_mode'],
                        $definition['target_cardinality'],
                        $definition['permission_match'],
                        $definition['permissions'],
                        array_map(static function (array $relation) use ($targets): OperationTargetType {
                            $target = $targets[$relation['target_resource_key']];
                            return new OperationTargetType(
                                $relation['target_role'],
                                $relation['target_resource_key'],
                                $target['resolver'],
                                $target['catalog_provider'],
                                $relation['input_mode'],
                                $relation['policy_selection_permission'] ?? null,
                            );
                        }, $definition['target_types']),
                    );
                    $operations[$operation->resourceKey][$operation->operation] = $operation;
                    $operationIds[$id] = $operation;
                    foreach ($definition['conditions'] ?? [] as $condition) {
                        $conditions[$id][$condition['key']][] = $condition['selector_resource_key'] ?? null;
                    }
                }
            }
        }
        $this->operations = $operations;
        $this->operationIds = $operationIds;
        $this->conditions = $conditions;
        $this->targets = $targets;
    }

    public function find(string $resourceKey, string $operation): ?ResourceOperation
    {
        return $this->operations[$resourceKey][$operation] ?? null;
    }

    public function findById(int $id): ?ResourceOperation
    {
        return $this->operationIds[$id] ?? null;
    }

    /** @return array<string, list<string|null>> */
    public function allowedConditions(int $operationId): array
    {
        return $this->conditions[$operationId] ?? [];
    }

    /** @return array<string, string>|null */
    public function targetType(string $key): ?array
    {
        return $this->targets[$key] ?? null;
    }

    public function findMany(array $operations): array
    {
        $found = [];
        foreach ($operations as $resourceKey => $operation) {
            $resolved = $this->find($resourceKey, $operation);
            if ($resolved !== null) {
                $found[$resourceKey] = $resolved;
            }
        }
        return $found;
    }

    public function availableOperations(int $tenantId, PageRequest $page): array
    {
        $available = [];
        $items = [];
        foreach ($this->operationIds as $operation) {
            $available[$operation->moduleKey] ??= $this->moduleAvailable($tenantId, $operation->moduleKey);
            if ($available[$operation->moduleKey]) {
                $items[] = $operation;
            }
        }
        usort($items, static fn(ResourceOperation $a, ResourceOperation $b): int =>
            [$a->resourceKey, $a->operation] <=> [$b->resourceKey, $b->operation]);
        return ['items' => array_slice($items, ($page->page - 1) * $page->pageSize, $page->pageSize), 'total' => count($items)];
    }

    public function moduleAvailable(int $tenantId, string $moduleKey): bool
    {
        return $this->entitlements->moduleAvailable($tenantId, $moduleKey);
    }

    public function registryRevision(): string
    {
        return $this->registry->revision;
    }
}
