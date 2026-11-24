<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use think\facade\Db;

/** Compares a proposed direct Platform API assignment with current instance authorization. */
final readonly class PlatformRoleAuthorizationPreview
{
    private const NODE_KEY = '/^platform\.[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*$/D';
    private const API_ID = '/^[a-f0-9]{64}$/D';

    public function __construct(
        private PlatformAuthorizationEvaluator $authorization,
        private ThinkPhpPlatformAuthorizationRepository $roles,
        private ApiPermissionCatalog $catalog,
        private DemoAccountPolicy $demoAccounts,
    ) {}

    /** @return array<string,mixed> */
    public function preview(
        PlatformContext $context,
        array $candidate,
        ModuleAvailability $modules,
        bool $mutationLocked = false,
    ): array {
        foreach (['platform.role.read', 'platform.permission.api.read', 'platform.operator.read'] as $permission) {
            // The existing evaluator reads actual role grants, independently of the HTTP request grant.
            $this->authorization->assertAllowed($context, $permission);
        }
        $this->fields($candidate, ['operator_id', 'nodes', 'role_assignments']);
        $operatorId = $this->positiveId($candidate['operator_id']);
        $nodes = $this->nodes($candidate['nodes']);
        $assignments = $this->assignments($candidate['role_assignments']);

        return Db::transaction(
            fn(): array => $this->compare($operatorId, $nodes, $assignments, $modules, $mutationLocked),
        );
    }

    private function compare(
        int $operatorId,
        array $nodes,
        array $assignments,
        ModuleAvailability $modules,
        bool $mutationLocked,
    ): array {
        $facts = $this->roles->roleFacts($operatorId);
        if ($facts['status'] === null) {
            throw AdminAccessException::notFound();
        }
        $active = $facts['status'] === 'active';
        $mutationLocked = $mutationLocked || $this->demoAccounts->platformMutationLocked($facts['account_id']);
        $permissionKeys = $this->roles->permissions($operatorId)->keys();
        $unresolved = $active ? [] : [['code' => 'operator_inactive']];

        $apis = [];
        foreach (Db::name('system_api')->where('audience', 'platform')->where('access_mode', 'platform_permission')
            ->where('status', 'active')
            ->field('id,method,path,module_key,default_permission_keys,prerequisite_permission_keys')
            ->order('path,method')->select()->toArray() as $api) {
            $apis[$api['id']] = $api;
        }
        $legacy = $this->legacyBindings($permissionKeys, $apis, $unresolved);
        $nodeApis = $this->nodeApis($nodes, $apis, $unresolved);
        foreach ($assignments as $roleId => $nodeKeys) {
            if (!isset($facts['roles'][$roleId])) {
                $unresolved[] = ['code' => 'role_not_assigned_to_operator', 'role_id' => (string) $roleId];
            }
        }

        $roleReports = [];
        $candidateIds = [];
        $dynamicIds = [];
        $ownerDynamic = false;
        $roleFacts = $facts['roles'];
        ksort($roleFacts, SORT_NUMERIC);
        foreach ($roleFacts as $roleId => $role) {
            $extra = ['role_id' => (string) $roleId];
            $assigned = array_key_exists($roleId, $assignments);
            if (!$assigned) {
                $unresolved[] = ['code' => 'role_assignment_missing', ...$extra];
            }
            if ($role['status'] !== 'active') {
                $unresolved[] = ['code' => $role['status'] === null ? 'role_missing' : 'role_inactive', ...$extra];
            }
            if ($role['unresolved_grant']) {
                $unresolved[] = ['code' => 'legacy_role_grant_not_active_platform', ...$extra];
            }
            $before = $this->apiIds($role['permission_keys'], $legacy);
            $after = [];
            $allNodesAssignable = true;
            if ($active && $role['status'] === 'active') {
                foreach ($assignments[$roleId] ?? [] as $nodeKey) {
                    if (!array_key_exists($nodeKey, $nodeApis)) {
                        $unresolved[] = ['code' => 'role_node_not_assignable', 'node_key' => $nodeKey, ...$extra];
                        $allNodesAssignable = false;
                        continue;
                    }
                    $after = array_merge($after, $nodeApis[$nodeKey]);
                }
            }
            if ($role['owner_dynamic']) {
                $dynamicIds = $this->apiIds($this->roles->bootstrapOwnerPermissionKeys(), $legacy);
                $ownerDynamic = true;
            }
            $changes = $this->difference($before, $after);
            $roleReports[] = [
                ...$extra,
                'relation_equal' => $assigned && $active && $role['status'] === 'active' && $allNodesAssignable
                    && !$role['unresolved_grant'] && $changes['added'] === [] && $changes['removed'] === [],
                'owner_dynamic' => $role['owner_dynamic'],
                ...$changes,
            ];
            $candidateIds = array_merge($candidateIds, $after);
        }

        $legacyIds = $this->apiIds($permissionKeys, $legacy);
        // Preview keeps independent and non-HTTP role actions in place. A direct API link cannot grant them.
        $allowedBefore = $this->effectiveIds($legacyIds, $apis, $permissionKeys, $modules, $mutationLocked);
        $allowedAfter = $this->effectiveIds([...$candidateIds, ...$dynamicIds], $apis, $permissionKeys, $modules, $mutationLocked);
        $changes = $this->difference($allowedBefore, $allowedAfter);
        $nonHttp = array_filter($permissionKeys, static fn(string $key): bool => ($legacy[$key] ?? []) === []);
        sort($nonHttp, SORT_STRING);

        return [
            'operator_id' => (string) $operatorId,
            'capability_equal' => $changes['added'] === [] && $changes['removed'] === [],
            'relation_coverage_complete' => $unresolved === [] && !in_array(false, array_column($roleReports, 'relation_equal'), true),
            'owner_dynamic' => $ownerDynamic,
            ...$changes,
            'non_http_permission_keys' => $nonHttp,
            'roles' => $roleReports,
            'unresolved' => $unresolved,
        ];
    }

    /** Existing instance bindings only. Source defaults are diagnostics, never fallback grants. */
    private function legacyBindings(array $permissionKeys, array $apis, array &$unresolved): array
    {
        if ($permissionKeys === []) {
            return [];
        }
        $rows = Db::name('permission')->alias('permission')
            ->leftJoin('permission_api binding', 'binding.permission_id = permission.id')
            ->where('permission.status', 'active')->whereIn('permission.key', $permissionKeys)
            ->field('permission.key AS permission_key,permission.api_configured,binding.api_id')
            ->order('permission.key,binding.api_id')->select()->toArray();

        $configured = [];
        $bindings = [];
        foreach ($rows as $row) {
            $key = $row['permission_key'];
            $configured[$key] = (bool) $row['api_configured'];
            if ($row['api_id'] === null) {
                continue;
            }
            $apiId = $row['api_id'];
            if (!isset($apis[$apiId])) {
                $unresolved[] = ['code' => 'legacy_api_not_active_platform', 'permission_key' => $key, 'api_id' => $apiId];
                continue;
            }
            $bindings[$key][$apiId] = $apiId;
        }
        foreach ($apis as $apiId => $api) {
            foreach (json_decode($api['default_permission_keys'], true, 32, JSON_THROW_ON_ERROR) as $key) {
                if (in_array($key, $permissionKeys, true) && !($configured[$key] ?? false) && !isset($bindings[$key][$apiId])) {
                    $unresolved[] = ['code' => 'legacy_default_not_materialized', 'permission_key' => $key, 'api_id' => $apiId];
                }
            }
        }

        return $bindings;
    }

    private function nodeApis(array $nodes, array $apis, array &$unresolved): array
    {
        $unavailable = [];
        if ($nodes !== []) {
            $registered = Db::name('system_menu')->whereIn('key', array_keys($nodes))
                ->field('key,scope,type,status,is_disable')->order('key')->select()->toArray();
            foreach ($registered as $menu) {
                $key = strtolower($menu['key']);
                if (!array_key_exists($key, $nodes)) {
                    continue;
                }
                if ($menu['key'] !== $key || $menu['scope'] !== 'platform' || $menu['status'] !== 'active' || (bool) $menu['is_disable']
                    || $menu['type'] !== $nodes[$key]['type']) {
                    $unavailable[$key] = true;
                    $unresolved[] = ['code' => 'registered_node_not_assignable', 'node_key' => $key];
                }
            }
        }

        $valid = [];
        foreach ($nodes as $nodeKey => $node) {
            if (isset($unavailable[$nodeKey])) {
                continue;
            }
            $visited = [];
            $current = $node;
            $cursor = $nodeKey;
            while ($current['parent_key'] !== null) {
                $visited[$cursor] = true;
                $cursor = $current['parent_key'];
                $parent = $nodes[$cursor] ?? null;
                if (isset($visited[$cursor]) || isset($unavailable[$cursor]) || $parent === null
                    || in_array($parent['type'], ['action', 'operation'], true)) {
                    $unresolved[] = ['code' => 'node_parent_invalid', 'node_key' => $nodeKey];
                    continue 2;
                }
                $current = $parent;
            }
            if ($node['type'] === 'group' && $node['api_ids'] !== []) {
                $unresolved[] = ['code' => 'group_cannot_grant_api', 'node_key' => $nodeKey];
                continue;
            }
            $allAssignable = true;
            foreach ($node['api_ids'] as $apiId) {
                if (!isset($apis[$apiId])) {
                    $unresolved[] = ['code' => 'api_not_active_platform', 'node_key' => $nodeKey, 'api_id' => $apiId];
                    $allAssignable = false;
                }
            }
            if ($allAssignable) {
                $valid[$nodeKey] = $node['api_ids'];
            }
        }

        return $valid;
    }

    private function apiIds(array $keys, array $bindings): array
    {
        $ids = [];
        foreach ($keys as $key) {
            $ids += $bindings[$key] ?? [];
        }
        return array_values($ids);
    }

    private function effectiveIds(array $ids, array $apis, array $keys, ModuleAvailability $modules, bool $locked): array
    {
        $rows = array_intersect_key($apis, array_fill_keys($ids, true));
        return array_column($this->catalog->applyPlatformAccessConstraints(array_values($rows), $keys, $modules, $locked), 'id');
    }

    private function difference(array $before, array $after): array
    {
        $added = array_values(array_diff(array_unique($after), $before));
        $removed = array_values(array_diff(array_unique($before), $after));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        return ['added' => $added, 'removed' => $removed];
    }

    private function nodes(mixed $value): array
    {
        $nodes = [];
        $edgeCount = 0;
        foreach ($this->list($value, 1000) as $node) {
            $node = $this->fields($node, ['key', 'parent_key', 'type', 'api_ids']);
            $key = $this->nodeKey($node['key']);
            $parent = $node['parent_key'] === null ? null : $this->nodeKey($node['parent_key']);
            $type = $node['type'];
            if (!in_array($type, ['group', 'page', 'action', 'operation'], true)) {
                throw $this->invalid();
            }
            $apiIds = $this->list($node['api_ids'], 2000);
            $edgeCount += count($apiIds);
            if ($edgeCount > 2000) {
                throw $this->invalid();
            }
            foreach ($apiIds as $id) {
                if (!is_string($id) || preg_match(self::API_ID, $id) !== 1) {
                    throw $this->invalid();
                }
            }
            $apiIds = array_unique($apiIds);
            sort($apiIds, SORT_STRING);
            $definition = ['parent_key' => $parent, 'type' => $type, 'api_ids' => $apiIds];
            if (isset($nodes[$key]) && $nodes[$key] !== $definition) {
                throw $this->invalid();
            }
            $nodes[$key] = $definition;
        }

        return $nodes;
    }

    private function assignments(mixed $value): array
    {
        $assignments = [];
        $selectionCount = 0;
        foreach ($this->list($value, 200) as $assignment) {
            $assignment = $this->fields($assignment, ['role_id', 'node_keys']);
            $roleId = $this->positiveId($assignment['role_id']);
            $nodes = $this->list($assignment['node_keys'], 1000);
            $selectionCount += count($nodes);
            if ($selectionCount > 2000) {
                throw $this->invalid();
            }
            $nodeKeys = array_unique(array_map($this->nodeKey(...), $nodes));
            sort($nodeKeys, SORT_STRING);
            if (isset($assignments[$roleId]) && $assignments[$roleId] !== $nodeKeys) {
                throw $this->invalid();
            }
            $assignments[$roleId] = $nodeKeys;
        }

        return $assignments;
    }

    private function fields(mixed $value, array $expected): array
    {
        if (!is_array($value) || count($value) !== count($expected) || array_diff($expected, array_keys($value)) !== []) {
            throw $this->invalid();
        }
        return $value;
    }

    private function list(mixed $value, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw $this->invalid();
        }
        return $value;
    }

    private function nodeKey(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 160 || preg_match(self::NODE_KEY, $value) !== 1) {
            throw $this->invalid();
        }
        return $value;
    }

    private function positiveId(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw $this->invalid();
        }
        return (int) $value;
    }

    private function invalid(): AdminAccessException
    {
        return AdminAccessException::invalid('ROLE_AUTHORIZATION_PREVIEW_INVALID', 'The platform authorization preview candidate is invalid.');
    }
}
