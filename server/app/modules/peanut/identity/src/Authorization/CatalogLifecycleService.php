<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization;

use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\facade\Db;

/**
 * Applies the Identity portion of an already authorized deployment catalog lifecycle.
 * The host owns approval, cross-owner blockers and the enclosing transaction.
 * Only fixed catalog actions are exposed, never a repository, table selector or ORM query.
 */
final readonly class CatalogLifecycleService
{
    /**
     * 已授权部署清单的首次登记；重复只接受相同且活动的身份，不能借重装覆盖版本或复活停用项。
     * 目录应用仍由宿主编排；本方法参与其外层事务，不开放任意字段更新或表选择。
     * @return array{key:string,version:string,schema:int,digest:string,status:string}
     */
    public function registerDeployedManifest(ManifestDocument $manifest): array
    {
        $identity = [
            'key' => (string) $manifest->data['key'],
            'version' => (string) $manifest->data['version'],
            'schema' => (int) $manifest->data['schema_version'],
            'digest' => $manifest->digest,
            'status' => 'active',
        ];
        $now = $this->now();
        return Db::transaction(function () use ($identity, $now): array {
            Db::name('module_installation')->duplicate(['module_key'])->insert([
                'module_key' => $identity['key'],
                'installed_version' => $identity['version'],
                'manifest_schema_version' => $identity['schema'],
                'manifest_digest' => $identity['digest'],
                'installed_at' => $now,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $current = Db::name('module_installation')->where('module_key', $identity['key'])
                ->field('installed_version,manifest_schema_version,manifest_digest,status')->lock(true)->find();
            if ($current === null) {
                throw new ModuleException('MODULE_INSTALLATION_FAILED', "Module installation record was not created: {$identity['key']}");
            }
            if ((string) ($current['installed_version'] ?? '') !== $identity['version']
                || (int) ($current['manifest_schema_version'] ?? 0) !== $identity['schema']
                || !hash_equals($identity['digest'], (string) ($current['manifest_digest'] ?? ''))
                || (string) ($current['status'] ?? '') !== $identity['status']) {
                throw new ModuleException('MODULE_INSTALLATION_MISMATCH', "Installed Module identity differs from the deployment registry: {$identity['key']}");
            }
            return $identity;
        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    public function beginPackageDeployment(array $manifests, bool $upgrade, string $now): void
    {
        foreach ($manifests as $manifest) {
            $moduleKey = (string) $manifest->data['key'];
            $values = [
                'installed_version' => $manifest->data['version'],
                'manifest_schema_version' => $manifest->data['schema_version'],
                'manifest_digest' => $manifest->digest,
                'status' => $upgrade ? 'upgrading' : 'installing',
                'last_error_code' => null,
                'updated_at' => $now,
            ];
            $exists = Db::name('module_installation')->where('module_key', $moduleKey)
                ->lock(true)->value('module_key');
            if ($exists === null) {
                Db::name('module_installation')->insert([
                    'module_key' => $moduleKey,
                    ...$values,
                    'revision' => 1,
                    'installed_at' => $now,
                    'created_at' => $now,
                ]);
                continue;
            }
            Db::name('module_installation')->where('module_key', $moduleKey)
                ->update([...$values, 'revision' => Db::raw('revision+1')]);
        }
    }

    /** @param array<string,ManifestDocument> $manifests */
    public function activatePackageDeployment(array $manifests, bool $upgrade, string $now): void
    {
        foreach ($manifests as $manifest) {
            $moduleKey = (string) $manifest->data['key'];
            $activatedAt = Db::name('module_installation')->where('module_key', $moduleKey)
                ->lock(true)->value('activated_at');
            Db::name('module_installation')->where('module_key', $moduleKey)->update([
                'installed_version' => $manifest->data['version'],
                'manifest_schema_version' => $manifest->data['schema_version'],
                'manifest_digest' => $manifest->digest,
                'status' => 'active',
                'revision' => Db::raw('revision+1'),
                'activated_at' => $activatedAt ?? $now,
                'upgraded_at' => $upgrade ? $now : null,
                'last_error_code' => null,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param list<string> $moduleKeys */
    public function markPackageDeploymentFailed(array $moduleKeys, string $errorCode, string $now): void
    {
        if ($moduleKeys !== []) {
            Db::name('module_installation')->whereIn('module_key', $moduleKeys)->update([
                'status' => 'failed',
                'revision' => Db::raw('revision+1'),
                'last_error_code' => $errorCode,
                'updated_at' => $now,
            ]);
        }
    }

    /** Preserve the historical error field while retiring installed members during normal uninstall. @param list<string> $moduleKeys */
    public function retirePackageModules(array $moduleKeys, string $now): void
    {
        if ($moduleKeys !== []) {
            Db::name('module_installation')->whereIn('module_key', $moduleKeys)->update([
                'status' => 'maintenance',
                'revision' => Db::raw('revision+1'),
                'updated_at' => $now,
            ]);
        }
    }

    /** Disable only currently active members, preserving the original database-clock timestamp semantics. @param list<string> $moduleKeys */
    public function disableActivePackageModules(array $moduleKeys): void
    {
        if ($moduleKeys !== []) {
            Db::name('module_installation')->whereIn('module_key', $moduleKeys)->where('status', 'active')->update([
                'status' => 'maintenance',
                'last_error_code' => null,
                'revision' => Db::raw('revision+1'),
                'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
        }
    }

    /** @param list<string> $moduleKeys */
    public function markPackageModulesForRecovery(array $moduleKeys, string $marker, string $now): void
    {
        if ($moduleKeys !== []) {
            Db::name('module_installation')->whereIn('module_key', $moduleKeys)->update([
                'status' => 'maintenance',
                'last_error_code' => $marker,
                'revision' => Db::raw('revision+1'),
                'updated_at' => $now,
            ]);
        }
    }

    /** @param list<string> $moduleKeys */
    public function finalizePackageRemoval(array $moduleKeys, bool $purge, string $now): void
    {
        if ($moduleKeys === []) {
            return;
        }
        if ($purge) {
            Db::name('module_installation')->whereIn('module_key', $moduleKeys)->delete();
            return;
        }
        Db::name('module_installation')->whereIn('module_key', $moduleKeys)->update([
            'status' => 'maintenance',
            'last_error_code' => null,
            'revision' => Db::raw('revision+1'),
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, ManifestDocument> $manifests */
    public function retireMissing(array $manifests): void
    {
        $moduleKeys = array_keys($manifests);
        if ($moduleKeys === []) {
            return;
        }
        $declared = ['pa_permission' => [], 'pa_protected_resource' => [], 'pa_target_type' => [], 'pa_data_condition_definition' => []];
        $operations = [];
        foreach ($manifests as $moduleKey => $manifest) {
            $catalog = is_array($manifest->data['catalog'] ?? null) ? $manifest->data['catalog'] : [];
            foreach (['permissions' => 'pa_permission', 'protected_resources' => 'pa_protected_resource', 'target_types' => 'pa_target_type', 'data_conditions' => 'pa_data_condition_definition'] as $manifestKey => $table) {
                foreach ((array) ($catalog[$manifestKey] ?? []) as $entry) {
                    if (is_array($entry) && is_string($entry['key'] ?? null)) {
                        $declared[$table][$moduleKey][] = $entry['key'];
                    }
                }
            }
            foreach ((array) ($catalog['protected_resources'] ?? []) as $resource) {
                if (!is_array($resource) || !is_string($resource['key'] ?? null)) {
                    continue;
                }
                foreach ((array) ($resource['operations'] ?? []) as $operation) {
                    if (is_array($operation) && is_string($operation['key'] ?? null)) {
                        $operations[$resource['key'] . "\0" . $operation['key']] = true;
                    }
                }
            }
        }
        Db::transaction(function () use ($declared, $operations, $moduleKeys): void {
            $now = $this->now();
            foreach ($declared as $table => $byModule) {
                foreach ($moduleKeys as $moduleKey) {
                    $this->retireMissingKeys($table, $moduleKey, array_values(array_unique($byModule[$moduleKey] ?? [])), $now);
                }
            }
            $rows = Db::table('pa_resource_operation')->alias('operation')
                ->join('pa_protected_resource resource', 'resource.id=operation.protected_resource_id')
                ->whereIn('resource.module_key', $moduleKeys)->where('operation.status', 'active')
                ->field('operation.id,resource.key AS resource_key,operation.operation')->order('operation.id')->select()->toArray();
            $missing = [];
            foreach ($rows as $row) {
                if (!isset($operations[(string) $row['resource_key'] . "\0" . (string) $row['operation']])) {
                    $missing[] = (string) $row['id'];
                }
            }
            if ($missing === []) {
                return;
            }
            $this->deleteByForeignIds('pa_resource_operation_permission', 'resource_operation_id', $missing);
            foreach (['pa_resource_operation_target_type', 'pa_resource_operation_condition'] as $table) {
                $this->updateByIds($table, $this->foreignIds($table, 'resource_operation_id', $missing), ['status' => 'retired']);
            }
            $this->updateByIds('pa_resource_operation', $missing, ['status' => 'retired', 'updated_at' => $now]);
        });
    }

    /** @return list<string> */
    public function activeModuleKeys(): array
    {
        $keys = [];
        foreach (['pa_permission', 'pa_protected_resource', 'pa_target_type', 'pa_data_condition_definition', 'pa_menu_definition'] as $table) {
            foreach (Db::table($table)->where('status', 'active')->distinct(true)->order('module_key')->column('module_key') as $key) {
                if (!in_array((string) $key, ['core', 'platform'], true)) {
                    $keys[(string) $key] = true;
                }
            }
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);
        return $result;
    }

    /** Logical resource references only; neither storage names, approval nor a database handle.
     * @param list<string> $moduleKeys
     * @return array{removed:list<array<string,mixed>>,preserved:list<array<string,mixed>>,blockers:list<array<string,mixed>>}
     */
    public function plan(array $moduleKeys, bool $purge): array
    {
        $permissions = $this->ids('pa_permission', $moduleKeys);
        $resources = $this->ids('pa_protected_resource', $moduleKeys);
        $targets = $this->ids('pa_target_type', $moduleKeys);
        $conditions = $this->ids('pa_data_condition_definition', $moduleKeys);
        $operations = $this->foreignIds('pa_resource_operation', 'protected_resource_id', $resources);
        $removed = [];
        $preserved = [];
        $blockers = $this->crossModuleBlockers($moduleKeys, $permissions, $targets, $conditions, $operations);
        if ($purge) {
            foreach ([
                ['pa_role_permission', $this->roleBindingIds($permissions, false)],
                ['pa_platform_role_permission', $this->roleBindingIds($permissions, true)],
                ['pa_menu_definition', $this->ids('pa_menu_definition', $moduleKeys)],
                ['pa_resource_operation_permission', $this->foreignIds('pa_resource_operation_permission', 'resource_operation_id', $operations)],
                ['pa_resource_operation_target_type', $this->foreignIds('pa_resource_operation_target_type', 'resource_operation_id', $operations)],
                ['pa_resource_operation_condition', $this->foreignIds('pa_resource_operation_condition', 'resource_operation_id', $operations)],
                ['pa_resource_operation', $operations], ['pa_protected_resource', $resources],
                ['pa_target_type', $targets], ['pa_data_condition_definition', $conditions], ['pa_permission', $permissions],
            ] as [$table, $ids]) {
                $this->append($removed, $table, 'delete', $ids);
            }
        } else {
            foreach ([
                ['pa_menu_definition', $this->activeIds('pa_menu_definition', $moduleKeys)],
                ['pa_resource_operation_target_type', $this->activeForeignIds('pa_resource_operation_target_type', 'resource_operation_id', $operations)],
                ['pa_resource_operation_condition', $this->activeForeignIds('pa_resource_operation_condition', 'resource_operation_id', $operations)],
                ['pa_resource_operation', $this->activeForeignIds('pa_resource_operation', 'protected_resource_id', $resources)],
                ['pa_protected_resource', $this->activeIds('pa_protected_resource', $moduleKeys)],
                ['pa_target_type', $this->activeIds('pa_target_type', $moduleKeys)],
                ['pa_data_condition_definition', $this->activeIds('pa_data_condition_definition', $moduleKeys)],
                ['pa_permission', $this->activeIds('pa_permission', $moduleKeys)],
            ] as [$table, $ids]) {
                $this->append($removed, $table, 'soft_retire', $ids);
            }
            $this->append($preserved, 'pa_role_permission', 'preserve', $this->roleBindingIds($permissions, false), true);
            $this->append($preserved, 'pa_platform_role_permission', 'preserve', $this->roleBindingIds($permissions, true), true);
        }
        $this->sortEntries($removed);
        $this->sortEntries($preserved);
        usort($blockers, static fn(array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));
        return ['removed' => $removed, 'preserved' => $preserved, 'blockers' => $blockers];
    }

    /** @param list<string> $moduleKeys */
    public function retire(array $moduleKeys): void
    {
        Db::transaction(function () use ($moduleKeys): void {
            $now = $this->now();
            $permissions = $this->ids('pa_permission', $moduleKeys);
            $resources = $this->ids('pa_protected_resource', $moduleKeys);
            $operations = $this->foreignIds('pa_resource_operation', 'protected_resource_id', $resources);
            $this->updateByIds('pa_permission', $permissions, ['status' => 'retired', 'retired_at' => $now, 'updated_at' => $now]);
            $this->updateByIds('pa_protected_resource', $resources, ['status' => 'retired', 'retired_at' => $now, 'updated_at' => $now]);
            foreach (['pa_target_type', 'pa_data_condition_definition'] as $table) {
                $this->updateByIds($table, $this->ids($table, $moduleKeys), ['status' => 'retired', 'updated_at' => $now]);
            }
            $this->updateByIds('pa_menu_definition', $this->ids('pa_menu_definition', $moduleKeys), [
                'status' => 'retired', 'updated_at' => $now,
                'upstream_defaults_json' => Db::raw("JSON_SET(upstream_defaults_json, '$.status', 'retired')"),
            ]);
            $this->updateByIds('pa_resource_operation', $operations, ['status' => 'retired', 'updated_at' => $now]);
            foreach (['pa_resource_operation_target_type', 'pa_resource_operation_condition'] as $table) {
                $this->updateByIds($table, $this->foreignIds($table, 'resource_operation_id', $operations), ['status' => 'retired']);
            }
        });
    }

    /** @param list<string> $moduleKeys */
    public function purge(array $moduleKeys): void
    {
        Db::transaction(function () use ($moduleKeys): void {
            $permissions = $this->ids('pa_permission', $moduleKeys);
            $resources = $this->ids('pa_protected_resource', $moduleKeys);
            $targets = $this->ids('pa_target_type', $moduleKeys);
            $conditions = $this->ids('pa_data_condition_definition', $moduleKeys);
            $operations = $this->foreignIds('pa_resource_operation', 'protected_resource_id', $resources);
            $this->deleteByForeignIds('pa_role_permission', 'permission_id', $permissions);
            $this->deleteByForeignIds('pa_platform_role_permission', 'permission_id', $permissions);
            $this->deleteByIds('pa_menu_definition', $this->ids('pa_menu_definition', $moduleKeys));
            foreach (['pa_resource_operation_permission', 'pa_resource_operation_target_type', 'pa_resource_operation_condition'] as $table) {
                $this->deleteByForeignIds($table, 'resource_operation_id', $operations);
            }
            $this->deleteByIds('pa_resource_operation', $operations);
            $this->deleteByIds('pa_protected_resource', $resources);
            $this->deleteByIds('pa_target_type', $targets);
            $this->deleteByIds('pa_data_condition_definition', $conditions);
            $this->deleteByIds('pa_permission', $permissions);
        });
    }

    /** @return list<string> */
    private function ids(string $table, array $moduleKeys): array
    {
        return $moduleKeys === [] ? [] : array_map('strval', Db::table($table)->whereIn('module_key', $moduleKeys)->order('id')->column('id'));
    }

    /** @return list<string> */
    private function activeIds(string $table, array $moduleKeys): array
    {
        return $moduleKeys === [] ? [] : array_map('strval', Db::table($table)->whereIn('module_key', $moduleKeys)->where('status', 'active')->order('id')->column('id'));
    }

    /** @return list<string> */
    private function foreignIds(string $table, string $column, array $ids): array
    {
        return $ids === [] ? [] : array_map('strval', Db::table($table)->whereIn($column, $ids)->order('id')->column('id'));
    }

    /** @return list<string> */
    private function activeForeignIds(string $table, string $column, array $ids): array
    {
        return $ids === [] ? [] : array_map('strval', Db::table($table)->whereIn($column, $ids)->where('status', 'active')->order('id')->column('id'));
    }

    /** @return list<string> */
    private function roleBindingIds(array $permissionIds, bool $platform): array
    {
        if ($permissionIds === []) {
            return [];
        }
        $table = $platform ? 'pa_platform_role_permission' : 'pa_role_permission';
        $fields = $platform ? 'platform_role_id,permission_id' : 'tenant_id,role_id,permission_id';
        $rows = Db::table($table)->whereIn('permission_id', $permissionIds)->field($fields)->select()->toArray();
        $ids = array_map(static fn(array $row): string => $platform
            ? 'platform_role_id=' . $row['platform_role_id'] . ';permission_id=' . $row['permission_id']
            : 'tenant_id=' . $row['tenant_id'] . ';role_id=' . $row['role_id'] . ';permission_id=' . $row['permission_id'], $rows);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return list<array{code:string,identifiers:list<string>}> */
    private function crossModuleBlockers(array $moduleKeys, array $permissionIds, array $targetIds, array $conditionIds, array $operationIds): array
    {
        $checks = [];
        if ($moduleKeys !== [] && $permissionIds !== []) {
            $checks['MODULE_CATALOG_EXTERNAL_MENU_REFERENCE'] = Db::table('pa_menu_definition')->whereNotIn('module_key', $moduleKeys)->whereIn('required_permission_id', $permissionIds)->order('id')->column('id');
        }
        if ($permissionIds !== []) {
            $checks['MODULE_CATALOG_EXTERNAL_PERMISSION_REFERENCE'] = $this->externalOperationReferenceIds('pa_resource_operation_permission', 'permission_id', $permissionIds, $operationIds);
        }
        if ($targetIds !== []) {
            $checks['MODULE_CATALOG_EXTERNAL_TARGET_REFERENCE'] = $this->externalOperationReferenceIds('pa_resource_operation_target_type', 'target_type_id', $targetIds, $operationIds);
        }
        if ($conditionIds !== []) {
            $checks['MODULE_CATALOG_EXTERNAL_CONDITION_REFERENCE'] = $this->externalOperationReferenceIds('pa_resource_operation_condition', 'condition_definition_id', $conditionIds, $operationIds);
        }
        $blockers = [];
        foreach ($checks as $code => $ids) {
            if ($ids !== []) {
                $blockers[] = ['code' => $code, 'identifiers' => array_map('strval', $ids)];
            }
        }
        return $blockers;
    }

    /** With no selected operations, every reference to a selected definition is external.
     * @return list<string>
     */
    private function externalOperationReferenceIds(string $table, string $column, array $definitionIds, array $operationIds): array
    {
        $query = Db::table($table)->whereIn($column, $definitionIds);
        if ($operationIds !== []) {
            $query->whereNotIn('resource_operation_id', $operationIds);
        }
        return array_map('strval', $query->order('id')->column('id'));
    }

    private function append(array &$entries, string $table, string $action, array $ids, bool $includeEmpty = false): void
    {
        sort($ids, SORT_STRING);
        if ($ids !== [] || $includeEmpty) {
            $resource = match ($table) {
                'pa_permission' => 'permissions',
                'pa_protected_resource' => 'protected-resources',
                'pa_target_type' => 'target-types',
                'pa_data_condition_definition' => 'data-conditions',
                'pa_menu_definition' => 'menu-definitions',
                'pa_resource_operation' => 'resource-operations',
                'pa_resource_operation_permission' => 'operation-permissions',
                'pa_resource_operation_target_type' => 'operation-targets',
                'pa_resource_operation_condition' => 'operation-conditions',
                'pa_role_permission' => 'tenant-role-grants',
                'pa_platform_role_permission' => 'platform-role-grants',
                default => throw new \LogicException('IDENTITY_CATALOG_RESOURCE_UNDECLARED'),
            };
            $entries[] = ['resource' => $resource, 'action' => $action, 'count' => count($ids), 'identifiers' => $ids];
        }
    }

    private function sortEntries(array &$entries): void
    {
        usort($entries, static fn(array $a, array $b): int => strcmp($a['resource'] . "\0" . $a['action'], $b['resource'] . "\0" . $b['action']));
    }

    private function updateByIds(string $table, array $ids, array $values): void
    {
        if ($ids !== []) {
            Db::table($table)->whereIn('id', $ids)->update($values);
        }
    }

    private function deleteByIds(string $table, array $ids): void
    {
        if ($ids !== []) {
            Db::table($table)->whereIn('id', $ids)->delete();
        }
    }

    private function deleteByForeignIds(string $table, string $column, array $ids): void
    {
        if ($ids !== []) {
            Db::table($table)->whereIn($column, $ids)->delete();
        }
    }

    private function retireMissingKeys(string $table, string $moduleKey, array $activeKeys, string $now): void
    {
        $query = Db::table($table)->where('module_key', $moduleKey)->where('status', 'active');
        if ($activeKeys !== []) {
            $query->whereNotIn('key', $activeKeys);
        }
        $values = ['status' => 'retired', 'updated_at' => $now];
        if (in_array($table, ['pa_permission', 'pa_protected_resource'], true)) {
            $values['retired_at'] = $now;
        }
        $query->update($values);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }
}
