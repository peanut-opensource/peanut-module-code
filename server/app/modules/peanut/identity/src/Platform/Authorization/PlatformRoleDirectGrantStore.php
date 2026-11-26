<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Platform\Authorization;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use think\facade\Db;

/**
 * Identity-owned role/node/API source. Staging and active reads use these same
 * normalized relations; legacy permission grants are never written alongside them.
 * The only switch is the global Platform source row, never a role-level fallback.
 */
final class PlatformRoleDirectGrantStore
{
    private static bool $sourceTablePreviouslyVerified = false;

    public function source(bool $lock = false): string
    {
        // Adopting source does not authorize a schema migration on the active
        // development DB. Legacy remains the single source until migration.
        // Once observed, loss of the source table never silently reactivates
        // legacy privileges; it fails at the next source query.
        if (!self::$sourceTablePreviouslyVerified) {
            $tables = $this->sourceTables();
            if (!isset($tables['pa_platform_role_authorization_source'])) {
                foreach ([
                    'pa_platform_role_direct_state',
                    'pa_platform_role_node',
                    'pa_platform_role_node_api',
                ] as $directTable) {
                    if (isset($tables[$directTable])) {
                        throw AdminAccessException::conflict(
                            'PLATFORM_AUTHORIZATION_SOURCE_INVALID',
                            'Direct relations without their source cannot fall back to legacy grants.',
                        );
                    }
                }
                return 'legacy';
            }
            self::$sourceTablePreviouslyVerified = true;
        }
        $query = Db::name('platform_role_authorization_source')->where('id', 1);
        if ($lock) {
            $query->lock(true);
        }
        $mode = $query->value('mode');
        if (!in_array($mode, ['legacy', 'direct'], true)) {
            throw AdminAccessException::conflict('PLATFORM_AUTHORIZATION_SOURCE_INVALID', 'Platform authorization source is not initialized.');
        }
        return $mode;
    }

    /** Fail closed before every direct write on an application without the isolated migration. */
    public function assertReady(): void
    {
        $tables = $this->sourceTables();
        foreach ([
            'pa_platform_role_authorization_source',
            'pa_platform_role_direct_state',
            'pa_platform_role_node',
            'pa_platform_role_node_api',
        ] as $required) {
            if (!isset($tables[$required])) {
                throw AdminAccessException::conflict(
                    'PLATFORM_DIRECT_SCHEMA_NOT_READY',
                    'Direct-role storage is not installed; existing roles remain under legacy authorization.',
                );
            }
        }
    }

    /** @return array<string,true> */
    private function sourceTables(): array
    {
        $driver = Db::connect()->getConfig('type');
        if ($driver === 'mysql') {
            $rows = Db::query("SHOW TABLES LIKE 'pa_platform_role_%'");
        } elseif ($driver === 'sqlite') {
            $rows = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'pa_platform_role_%'");
        } else {
            throw AdminAccessException::conflict('PLATFORM_AUTHORIZATION_SOURCE_INVALID', 'Unsupported authorization database.');
        }
        $found = [];
        foreach ($rows as $row) {
            if (is_array($row) && $row !== []) {
                $found[(string) reset($row)] = true;
            }
        }
        return $found;
    }

    /**
     * The server's current registered nodes AND the live API/bundle and menu facts
     * contribute to the save precondition; role revision alone cannot freeze these.
     * Call inside the writer's database transaction.
     *
     * @param list<array<string,mixed>> $nodes
     */
    public function catalogDigest(array $nodes, bool $lock = false): string
    {
        $apis = Db::name('system_api')->where('audience', 'platform')->order('id');
        // Platform source digests must not drift when a tenant menu/admin API
        // binding changes. Audience and route ownership are independent RBAC
        // domains; only platform-audience bindings belong to this snapshot.
        $bindings = Db::name('permission_api')->alias('binding')
            ->join('system_api api', 'api.id = binding.api_id')
            ->where('api.audience', 'platform')
            ->field('binding.permission_id,binding.api_id')
            ->order('binding.permission_id,binding.api_id');
        // Permission activation/retirement changes the effective projection
        // even when neither the API record nor its binding rows change.
        $permissions = Db::name('permission')->whereLike('key', 'platform.%')->order('id');
        $menus = Db::name('system_menu')->where('scope', 'platform')->order('key');
        if ($lock) {
            $apis->lock(true);
            $bindings->lock(true);
            $permissions->lock(true);
            $menus->lock(true);
        }
        $canonical = ['nodes' => $nodes, 'apis' => $apis->select()->toArray(),
            'permission_api' => $bindings->select()->toArray(),
            'permissions' => $permissions->select()->toArray(), 'menus' => $menus->select()->toArray()];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Only a server-side resolver may supply the complete registered node graph.
     * This also validates the exact per-role selection after source resolution.
     *
     * @param array<string,mixed> $resolved
     * @param list<string> $selected
     * @return array<string,list<string>>
     */
    public function selectedNodes(array $resolved, int $roleId, int $revision, array $selected): array
    {
        $expected = [['role_id' => $roleId, 'expected_revision' => $revision, 'node_keys' => $selected]];
        if (($resolved['role_ids'] ?? null) !== [$roleId] ||
            ($resolved['role_assignments'] ?? null) !== $expected ||
            !is_array($resolved['nodes'] ?? null) || !array_is_list($resolved['nodes'])) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_NODE_SOURCE_INVALID', 'The current registered source did not resolve the role selection.');
        }
        $known = [];
        foreach ($resolved['nodes'] as $node) {
            if (!is_array($node) || !is_string($node['key'] ?? null) ||
                !in_array($node['type'] ?? null, ['group', 'page', 'action', 'operation'], true) ||
                !is_array($node['api_ids'] ?? null) || isset($known[$node['key']])) {
                throw AdminAccessException::invalid('PLATFORM_ROLE_NODE_SOURCE_INVALID', 'Registered node graph is invalid.');
            }
            $known[$node['key']] = $node;
        }
        if (count($selected) !== count(array_unique($selected)) || count($selected) > 200) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_NODES_INVALID', 'The role node selection is invalid.');
        }
        $result = [];
        foreach ($selected as $key) {
            $node = $known[$key] ?? null;
            if (!is_string($key) || $node === null || $node['type'] === 'group' ||
                ($node['type'] === 'page' && $node['api_ids'] === [])) {
                throw AdminAccessException::invalid('PLATFORM_ROLE_NODE_NOT_REGISTERED', 'Selected node is not an assignable registered Platform node.');
            }
            if (count($node['api_ids']) !== count(array_unique($node['api_ids']))) {
                throw AdminAccessException::invalid('PLATFORM_ROLE_NODE_SOURCE_INVALID', 'Duplicate node API association.');
            }
            foreach ($node['api_ids'] as $apiId) {
                if (!is_string($apiId) || preg_match('/^[a-f0-9]{64}$/D', $apiId) !== 1) {
                    throw AdminAccessException::invalid('PLATFORM_ROLE_NODE_SOURCE_INVALID', 'Invalid registered API identity.');
                }
            }
            $result[$key] = $node['api_ids'];
        }

        return $result;
    }

    /**
     * @param array<string,list<string>> $nodes
     * @param list<string> $approvedKeys
     * @param list<string> $auxiliaryKeys
     */
    public function save(int $roleId, int $revision, array $nodes, array $approvedKeys, array $auxiliaryKeys, string $digest, string $now): void
    {
        Db::name('platform_role_node_api')->where('platform_role_id', $roleId)->delete();
        Db::name('platform_role_node')->where('platform_role_id', $roleId)->delete();
        Db::name('platform_role_direct_state')->where('platform_role_id', $roleId)->delete();
        $auxiliaryKeys = array_values(array_unique($auxiliaryKeys));
        sort($auxiliaryKeys, SORT_STRING);
        $approvedKeys = array_values(array_unique($approvedKeys));
        sort($approvedKeys, SORT_STRING);
        Db::name('platform_role_direct_state')->insert([
            'platform_role_id' => $roleId,
            'role_revision' => $revision,
            'explicit_empty' => $nodes === [] ? 1 : 0,
            'approved_permission_keys_json' => json_encode($approvedKeys, JSON_THROW_ON_ERROR),
            'auxiliary_permission_keys_json' => json_encode($auxiliaryKeys, JSON_THROW_ON_ERROR),
            'catalog_sha256' => $digest,
            'updated_at' => $now,
        ]);
        $nodeRows = [];
        $apiRows = [];
        foreach ($nodes as $key => $apiIds) {
            $nodeRows[] = ['platform_role_id' => $roleId, 'node_key' => $key];
            foreach ($apiIds as $apiId) {
                $apiRows[] = ['platform_role_id' => $roleId, 'node_key' => $key, 'api_id' => $apiId];
            }
        }
        foreach (array_chunk($nodeRows, 200) as $chunk) {
            Db::name('platform_role_node')->insertAll($chunk);
        }
        foreach (array_chunk($apiRows, 200) as $chunk) {
            Db::name('platform_role_node_api')->insertAll($chunk);
        }
    }

    /**
     * The explicit selected API set is projected through current registered
     * bundles only at the time of a privileged source write. The resulting key
     * list is frozen in the same direct role state, not re-invented on reads.
     *
     * @param list<string> $apiIds
     * @return list<string>
     */
    public function approvedHttpKeys(array $apiIds): array
    {
        if ($apiIds === []) {
            return [];
        }
        $selected = array_fill_keys($apiIds, true);
        $rows = Db::name('permission_api')->alias('binding')
            ->join('permission permission', 'permission.id = binding.permission_id')
            ->where('permission.status', 'active')->whereLike('permission.key', 'platform.%')
            ->field('permission.key,binding.api_id')->select()->toArray();
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']][$row['api_id']] = true;
        }
        $approved = [];
        foreach ($byKey as $key => $bindings) {
            if (array_diff_key($bindings, $selected) === []) {
                $approved[] = $key;
            }
        }
        sort($approved, SORT_STRING);
        return $approved;
    }

    /**
     * For direct mode, permission keys are only projected when every live API
     * bound to that permission is selected. This is intentionally fail-closed:
     * an API bundle shared with an unselected endpoint is never over-granted.
     *
     * @return array{status:?string,account_id:?int,roles:array<int,array<string,mixed>>}
     */
    public function roleFacts(int $operatorId): array
    {
        $rows = Db::name('platform_operator')->alias('operator')
            ->leftJoin('platform_operator_role membership', 'membership.platform_operator_id = operator.id')
            ->leftJoin('platform_role role', 'role.id = membership.platform_role_id')
            ->where('operator.id', $operatorId)
            ->field('operator.status AS operator_status,operator.account_id,role.id AS role_id,role.key AS role_key,role.status AS role_status,role.is_builtin,role.revision')
            ->order('role.id')->select()->toArray();
        if ($rows === []) {
            return ['status' => null, 'account_id' => null, 'roles' => []];
        }
        $status = $rows[0]['operator_status'];
        $roles = [];
        $revisions = [];
        foreach ($rows as $row) {
            if ($row['role_id'] !== null && $status === 'active' && $row['role_status'] === 'active') {
                $revisions[(int) $row['role_id']] = (int) $row['revision'];
            }
        }
        $directKeys = $this->rolePermissionKeysBatch($revisions);
        foreach ($rows as $row) {
            if ($row['role_id'] === null) {
                continue;
            }
            $roleId = (int) $row['role_id'];
            $active = $status === 'active' && $row['role_status'] === 'active';
            $roles[$roleId] = [
                'status' => $row['role_status'],
                'permission_keys' => $active ? $directKeys[$roleId] : [],
                'unresolved_grant' => false,
                'owner_dynamic' => $active && $row['role_key'] === 'platform.bootstrap-owner' && (int) $row['is_builtin'] === 1,
            ];
        }
        return ['status' => $status, 'account_id' => (int) $rows[0]['account_id'], 'roles' => $roles];
    }

    /** @return list<string> */
    public function rolePermissionKeys(int $roleId, int $roleRevision): array
    {
        return $this->rolePermissionKeysBatch([$roleId => $roleRevision])[$roleId];
    }

    /**
     * Batch the directory, API bindings and role selections once per read; do
     * not query the catalog inside a role/operator loop.
     *
     * @param array<int,int> $roleRevisions
     * @return array<int,list<string>>
     */
    public function rolePermissionKeysBatch(array $roleRevisions): array
    {
        if ($roleRevisions === []) {
            return [];
        }
        $roleIds = array_keys($roleRevisions);
        $states = [];
        foreach (Db::name('platform_role_direct_state')->whereIn('platform_role_id', $roleIds)->select()->toArray() as $state) {
            $states[(int) $state['platform_role_id']] = $state;
        }
        $selected = [];
        $allSelected = [];
        foreach (Db::name('platform_role_node_api')->whereIn('platform_role_id', $roleIds)
            ->field('platform_role_id,api_id')->select()->toArray() as $row) {
            $roleId = (int) $row['platform_role_id'];
            $selected[$roleId][$row['api_id']] = true;
            $allSelected[$row['api_id']] = true;
        }
        $live = $allSelected === [] ? [] : array_fill_keys(
            Db::name('system_api')->whereIn('id', array_keys($allSelected))
                ->where('audience', 'platform')->where('status', 'active')
                ->where('access_mode', 'platform_permission')->column('id'),
            true,
        );
        $bindings = Db::name('permission_api')->alias('binding')
            ->join('permission permission', 'permission.id = binding.permission_id')
            ->whereLike('permission.key', 'platform.%')
            ->where('permission.status', 'active')
            ->field('permission.key,binding.api_id')->select()->toArray();
        $byKey = [];
        foreach ($bindings as $binding) {
            $byKey[$binding['key']][$binding['api_id']] = true;
        }
        $rawKeysByRole = [];
        $allKeys = [];
        foreach ($roleRevisions as $roleId => $revision) {
            $state = $states[$roleId] ?? null;
            if ($state === null || (int) $state['role_revision'] !== $revision) {
                throw AdminAccessException::conflict('PLATFORM_DIRECT_ROLE_NOT_MIGRATED', 'Role direct authorization does not match its revision.');
            }
            $roleSelected = $selected[$roleId] ?? [];
            if (array_diff_key($roleSelected, $live) !== [] ||
                ((int) $state['explicit_empty'] === 1 && $roleSelected !== [])) {
                throw AdminAccessException::conflict('PLATFORM_DIRECT_API_CATALOG_DRIFT', 'Selected role API is no longer valid.');
            }
            $keys = [];
            $approved = json_decode((string) $state['approved_permission_keys_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($approved) || !array_is_list($approved)) {
                throw AdminAccessException::conflict('PLATFORM_DIRECT_ROLE_INVALID', 'Approved direct permission keys are invalid.');
            }
            foreach ($approved as $key) {
                if (!is_string($key) || !str_starts_with($key, 'platform.') ||
                    !isset($byKey[$key]) || array_diff_key($byKey[$key], $roleSelected) !== []) {
                    throw AdminAccessException::conflict('PLATFORM_DIRECT_API_CATALOG_DRIFT', 'Approved role API mapping has changed.');
                }
                $keys[$key] = true;
            }
            $auxiliary = json_decode((string) $state['auxiliary_permission_keys_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($auxiliary) || !array_is_list($auxiliary)) {
                throw AdminAccessException::conflict('PLATFORM_DIRECT_ROLE_INVALID', 'Direct role auxiliary grants cannot authorize HTTP endpoints.');
            }
            foreach ($auxiliary as $key) {
                if (!is_string($key) || !str_starts_with($key, 'platform.') || isset($byKey[$key])) {
                    throw AdminAccessException::conflict('PLATFORM_DIRECT_ROLE_INVALID', 'Direct role auxiliary grants cannot authorize HTTP endpoints.');
                }
                $keys[$key] = true;
            }
            $rawKeysByRole[$roleId] = $keys;
            $allKeys += $keys;
        }
        $active = $allKeys === [] ? [] : array_fill_keys(
            Db::name('permission')->whereIn('key', array_keys($allKeys))->where('status', 'active')->column('key'),
            true,
        );
        $result = [];
        foreach ($rawKeysByRole as $roleId => $keys) {
            $result[$roleId] = array_keys(array_intersect_key($keys, $active));
            sort($result[$roleId], SORT_STRING);
        }
        return $result;
    }
}
