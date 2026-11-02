<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Catalog;

use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Authorization\Model\DataConditionDefinitionRecord;
use PeanutAdmin\Modules\Identity\Authorization\Model\ProtectedResourceRecord;
use PeanutAdmin\Modules\Identity\Authorization\Model\ResourceOperationPermissionRecord;
use PeanutAdmin\Modules\Identity\Authorization\Model\ResourceOperationRecord;
use PeanutAdmin\Modules\Identity\Authorization\Model\ResourceOperationTargetTypeRecord;
use PeanutAdmin\Modules\Identity\Authorization\Model\TargetTypeRecord;
use PeanutAdmin\Modules\Identity\Module\Model\ModuleInstallation;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

final readonly class ThinkPhpResourceOperationCatalog implements \PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog
{
    public function __construct(private TenantEntitlementService $entitlements) {}

    public function find(string $resourceKey, string $operation): ?\PeanutAdmin\DataPermission\Catalog\ResourceOperation
    {
        return $this->findMany([$resourceKey => $operation])[$resourceKey] ?? null;
    }

    /** @param array<string, string> $operations @return array<string, \PeanutAdmin\DataPermission\Catalog\ResourceOperation> */
    public function findMany(array $operations): array
    {
        $found = [];
        foreach (array_chunk($operations, 500, true) as $selectedOperations) {
            $selected = [];
            $bindings = [];
            foreach ($selectedOperations as $resourceKey => $operation) {
                $index = count($selected);
                $selected[] = 'SELECT :resource_' . $index . ' AS resource_key, :operation_' . $index . ' AS operation';
                $bindings['resource_' . $index] = $resourceKey;
                $bindings['operation_' . $index] = $operation;
            }
            // SQL performs the same key comparisons as find(), while retaining each caller key.
            $rows = ResourceOperationRecord::alias('ro')
                ->join('protected_resource pr', "pr.id = ro.protected_resource_id AND pr.status = 'active'")
                ->join('(' . implode(' UNION ALL ', $selected) . ') requested', 'requested.resource_key = pr.key AND requested.operation = ro.operation')
                ->bind($bindings)
                ->leftJoin('resource_operation_permission relation', 'relation.resource_operation_id = ro.id')
                ->leftJoin('permission permission', "permission.id = relation.permission_id AND permission.status = 'active'")
                ->where('ro.status', 'active')
                ->field([
                    'requested.resource_key' => 'requested_resource_key',
                    'ro.id', 'ro.protected_resource_id', 'ro.operation', 'ro.access_mode',
                    'ro.target_cardinality', 'ro.permission_match', 'pr.key' => 'resource_key',
                    'pr.module_key', 'pr.provider_key', 'pr.ownership', 'permission.key' => 'permission_key',
                ])
                ->order('relation.sort_order')->order('permission.key')->select()->toArray();
            $records = [];
            $permissions = [];
            foreach ($rows as $row) {
                $key = (string) $row['requested_resource_key'];
                $records[$key] ??= $row;
                $permissions[$key] ??= [];
                if ($row['permission_key'] !== null) {
                    $permissions[$key][] = (string) $row['permission_key'];
                }
            }
            // Keep both child collections separate; joining them multiplies permission/target rows.
            $targets = $this->targetTypes(array_values(array_unique(array_column($records, 'id'))));
            foreach ($selectedOperations as $key => $operation) {
                if (isset($records[$key])) {
                    $row = $records[$key];
                    $found[$key] = $this->operation($row, $permissions[$key], $targets[(int) $row['id']] ?? []);
                }
            }
        }
        return $found;
    }

    public function availableOperations(int $tenantId, PageRequest $page): array
    {
        $modules = ['core', ...$this->entitlements->moduleKeys($tenantId)];
        $query = ResourceOperationRecord::alias('ro')
            ->join('protected_resource pr', "pr.id = ro.protected_resource_id AND pr.status = 'active'")
            ->where('ro.status', 'active')
            ->whereIn('pr.module_key', array_values(array_unique(array_map('strval', $modules))));
        $total = (clone $query)->count();
        if ($total === 0 || $page->page > intdiv((int) $total - 1, $page->pageSize) + 1) {
            return ['items' => [], 'total' => (int) $total];
        }
        $rows = $query->field([
            'ro.id', 'ro.protected_resource_id', 'ro.operation', 'ro.access_mode',
            'ro.target_cardinality', 'ro.permission_match', 'pr.key' => 'resource_key',
            'pr.module_key', 'pr.provider_key', 'pr.ownership',
        ])->order('pr.key')->order('ro.operation')->order('ro.id')
            ->page($page->page, $page->pageSize)
            ->select()
            ->toArray();

        return [
            'items' => array_values(array_map(fn(array $row): \PeanutAdmin\DataPermission\Catalog\ResourceOperation => $this->operation($row), $rows)),
            'total' => (int) $total,
        ];
    }

    public function moduleAvailable(int $tenantId, string $moduleKey): bool
    {
        if ($moduleKey === 'core') {
            return true;
        }

        return $this->entitlements->moduleAvailable($tenantId, $moduleKey);
    }

    public function registryRevision(): string
    {
        // One native UNION ALL keeps the same complete revision inputs without five round trips.
        $query = ProtectedResourceRecord::fieldRaw("CONCAT('resource:',id,':',status,':',manifest_digest) AS digest")
            ->unionAll([
                ResourceOperationRecord::fieldRaw("CONCAT('operation:',id,':',status,':',manifest_digest) AS digest")->buildSql(false),
                ModuleInstallation::fieldRaw("CONCAT('module-installation:',module_key,':',status,':',revision,':',manifest_digest) AS digest")->buildSql(false),
                TargetTypeRecord::fieldRaw("CONCAT('target:',id,':',status,':',manifest_digest) AS digest")->buildSql(false),
                DataConditionDefinitionRecord::fieldRaw("CONCAT('condition:',id,':',status,':',manifest_digest) AS digest")->buildSql(false),
            ]);
        $digests = array_column(\think\facade\Db::query($query->buildSql(false)), 'digest');
        sort($digests, SORT_STRING);

        return hash('sha256', implode('|', $digests));
    }

    /** @param array<string, mixed> $row @param list<string>|null $permissionKeys
     * @param list<\PeanutAdmin\DataPermission\Catalog\OperationTargetType>|null $targetTypes
     */
    private function operation(array $row, ?array $permissionKeys = null, ?array $targetTypes = null): \PeanutAdmin\DataPermission\Catalog\ResourceOperation
    {
        $id = (int) $row['id'];

        return new \PeanutAdmin\DataPermission\Catalog\ResourceOperation(
            $id,
            (int) $row['protected_resource_id'],
            (string) $row['resource_key'],
            (string) $row['module_key'],
            (string) $row['provider_key'],
            (string) $row['ownership'],
            (string) $row['operation'],
            (string) $row['access_mode'],
            (string) $row['target_cardinality'],
            (string) $row['permission_match'],
            $permissionKeys ?? $this->permissionKeys($id),
            $targetTypes ?? ($this->targetTypes([$id])[$id] ?? []),
        );
    }

    /** @return list<string> */
    private function permissionKeys(int $operationId): array
    {
        return array_values(array_map('strval', ResourceOperationPermissionRecord::alias('relation')
            ->join('permission p', "p.id = relation.permission_id AND p.status = 'active'")
            ->where('relation.resource_operation_id', $operationId)
            ->order('relation.sort_order')
            ->order('p.key')
            ->column('p.key')));
    }

    /** @param list<int> $operationIds @return array<int, list<\PeanutAdmin\DataPermission\Catalog\OperationTargetType>> */
    private function targetTypes(array $operationIds): array
    {
        if ($operationIds === []) {
            return [];
        }
        $rows = ResourceOperationTargetTypeRecord::alias('relation')
            ->join('target_type target', "target.id = relation.target_type_id AND target.status = 'active'")
            ->leftJoin('permission selection_permission', "selection_permission.id = relation.policy_selection_permission_id AND selection_permission.status = 'active'")
            ->whereIn('relation.resource_operation_id', $operationIds)
            ->where('relation.status', 'active')
            ->field([
                'relation.resource_operation_id',
                'relation.target_role', 'relation.input_mode', 'target.key', 'target.resolver_key',
                'target.catalog_provider_key', 'selection_permission.key' => 'policy_selection_permission_key',
            ])
            ->order('relation.resource_operation_id')->order('relation.target_role')
            ->order('target.key')
            ->select()
            ->toArray();

        $targets = [];
        foreach ($rows as $row) {
            $targets[(int) $row['resource_operation_id']][] = new \PeanutAdmin\DataPermission\Catalog\OperationTargetType(
                (string) $row['target_role'],
                (string) $row['key'],
                (string) $row['resolver_key'],
                (string) $row['catalog_provider_key'],
                (string) $row['input_mode'],
                is_string($row['policy_selection_permission_key']) ? $row['policy_selection_permission_key'] : null,
            );
        }
        return $targets;
    }
}
