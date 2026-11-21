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
 * Composite owners suppress inner invalidation and advance revisions once in their
 * enclosing native transaction after all catalog and installation writes.
 */
final readonly class CatalogLifecycleService
{
    /** Development catalog sync may adopt new contributions at the same active package version.
     * It cannot install, upgrade, reactivate or change package ownership.
     * @param array<string,ManifestDocument> $manifests
     */
    public function refreshActiveCatalogIdentities(array $manifests): int
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        $changed = 0;
        foreach ($manifests as $manifest) {
            $changed += Db::name('module_installation')
                ->where('module_key', $manifest->data['key'])->where('status', 'active')
                ->where('installed_version', $manifest->data['version'])
                ->where('manifest_schema_version', $manifest->data['schema_version'])
                ->where('manifest_digest', '<>', $manifest->digest)
                ->update(['manifest_digest' => $manifest->digest, 'revision' => Db::raw('revision+1'),
                    'updated_at' => $this->now()]);
        }
        return $changed;
    }

    /** Owners reserved for the platform, host and instance, not Module lifecycle. */
    public const NON_MODULE_OWNERS = ['core', 'platform', 'peanut.admin', 'instance.tenant', 'instance.platform', 'member', 'installation'];

    /**
     * 已授权部署清单的首次登记；重复只接受相同且活动的身份，不能借重装覆盖版本或复活停用项。
     * 目录应用仍由宿主编排；本方法参与其外层事务，不开放任意字段更新或表选择。
     * @return array{key:string,version:string,schema:int,digest:string,status:string}
     */
    public function registerDeployedManifest(ManifestDocument $manifest, bool $invalidate = true): array
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        $identity = [
            'key' => (string) $manifest->data['key'],
            'version' => (string) $manifest->data['version'],
            'schema' => (int) $manifest->data['schema_version'],
            'digest' => $manifest->digest,
            'status' => 'active',
        ];
        $now = $this->now();
        return Db::transaction(function () use ($identity, $now, $invalidate): array {
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
            if ($invalidate) {
                app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization([$identity['key']]);
            }
            return $identity;
        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    public function beginPackageDeployment(array $manifests, bool $upgrade, string $now): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        Db::transaction(function () use ($manifests, $upgrade, $now): void {
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
            app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization(array_keys($manifests));
        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    public function activatePackageDeployment(array $manifests, bool $upgrade, string $now, bool $invalidate = true): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        Db::transaction(function () use ($manifests, $upgrade, $now, $invalidate): void {
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
                    'uninstalled_at' => null,
                    'last_error_code' => null,
                    'updated_at' => $now,
                ]);
            }
            if ($invalidate) {
                app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization(array_keys($manifests));
            }
        });
    }

    /** @param list<string> $moduleKeys */
    public function markPackageDeploymentFailed(array $moduleKeys, string $errorCode, string $now): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        Db::transaction(function () use ($moduleKeys, $errorCode, $now): void {
            if ($moduleKeys !== []) {
                Db::name('module_installation')->whereIn('module_key', $moduleKeys)->update([
                    'status' => 'failed',
                    'revision' => Db::raw('revision+1'),
                    'last_error_code' => $errorCode,
                    'updated_at' => $now,
                ]);
            }
            app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization($moduleKeys);
        });
    }

    /** Disable only currently active members, preserving the original database-clock timestamp semantics. @param list<string> $moduleKeys */
    public function disableActivePackageModules(array $moduleKeys, bool $invalidate = true): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        Db::transaction(function () use ($moduleKeys, $invalidate): void {
            if ($moduleKeys !== []) {
                Db::name('module_installation')->whereIn('module_key', $moduleKeys)->where('status', 'active')->update([
                    'status' => 'maintenance',
                    'last_error_code' => null,
                    'revision' => Db::raw('revision+1'),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            }
            if ($invalidate) {
                app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization($moduleKeys);
            }
        });
    }

    /** @param list<string> $moduleKeys */
    public function markPackageModulesForRecovery(array $moduleKeys, string $marker, string $now): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        Db::transaction(function () use ($moduleKeys, $marker, $now): void {
            if ($moduleKeys !== []) {
                Db::name('module_installation')->whereIn('module_key', $moduleKeys)->update([
                    'status' => 'maintenance',
                    'last_error_code' => $marker,
                    'revision' => Db::raw('revision+1'),
                    'updated_at' => $now,
                ]);
            }
            app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization($moduleKeys);
        });
    }

    /** @param list<string> $moduleKeys */
    public function finalizePackageRemoval(array $moduleKeys, bool $purge, string $now): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        Db::transaction(function () use ($moduleKeys, $purge, $now): void {
            if ($moduleKeys === []) {
                return;
            }
            // Purge removes owned business resources, never the sole lifecycle/recovery identity.
            Db::name('module_installation')->whereIn('module_key', $moduleKeys)->update([
                'status' => 'uninstalled', 'uninstalled_at' => $now,
                'last_error_code' => null,
                'revision' => Db::raw('revision+1'),
                'updated_at' => $now,
            ]);
            app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization($moduleKeys);
        });
    }

    /** @param array<string, ManifestDocument> $manifests */
    public function retireMissing(array $manifests, bool $invalidate = true): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        $moduleKeys = array_keys($manifests);
        if ($moduleKeys === []) {
            return;
        }
        $declared = ['pa_permission' => []];
        foreach ($manifests as $moduleKey => $manifest) {
            $catalog = is_array($manifest->data['catalog'] ?? null) ? $manifest->data['catalog'] : [];
            foreach ((array) ($catalog['permissions'] ?? []) as $entry) {
                if (is_array($entry) && is_string($entry['key'] ?? null)) {
                    $declared['pa_permission'][$moduleKey][] = $entry['key'];
                }
            }
        }
        Db::transaction(function () use ($declared, $moduleKeys, $invalidate): void {
            $now = $this->now();
            foreach ($declared as $table => $byModule) {
                foreach ($moduleKeys as $moduleKey) {
                    $this->retireMissingKeys($table, $moduleKey, array_values(array_unique($byModule[$moduleKey] ?? [])), $now);
                }
            }
            if ($invalidate) {
                app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization($moduleKeys);
            }
        });
    }

    /** @return list<string> */
    public function activeModuleKeys(): array
    {
        $keys = [];
        foreach (['pa_permission', 'pa_system_menu'] as $table) {
            foreach (Db::table($table)->where('status', 'active')->distinct(true)->order('module_key')->column('module_key') as $key) { // @phpstan-ignore peanut.rawDbQuery (The fixed table list contains only global permission and menu definitions.)
                if (!in_array((string) $key, self::NON_MODULE_OWNERS, true)) {
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
        $removed = [];
        $preserved = [];
        $blockers = $this->crossModuleBlockers($moduleKeys, $permissions, $purge);
        $this->append($removed, 'pa_system_api', 'soft_retire', $this->activeIds('pa_system_api', $moduleKeys));
        if ($purge) {
            foreach ([
                ['pa_role_permission', $this->roleBindingIds($permissions, false)],
                ['pa_platform_role_permission', $this->roleBindingIds($permissions, true)],
                ['pa_permission_api', $this->foreignIds('pa_permission_api', 'permission_id', $permissions)],
                ['pa_system_menu', $this->ids('pa_system_menu', $moduleKeys)],
                ['pa_permission', $permissions],
            ] as [$table, $ids]) {
                $this->append($removed, $table, 'delete', $ids);
            }
        } else {
            foreach ([
                ['pa_system_menu', $this->activeIds('pa_system_menu', $moduleKeys)],
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
    public function retire(array $moduleKeys, bool $invalidate = true): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        Db::transaction(function () use ($moduleKeys, $invalidate): void {
            $now = $this->now();
            if ($moduleKeys !== []) {
                Db::name('system_api')->whereIn('module_key', $moduleKeys)->where('status', 'active')->update([
                    'status' => 'retired', 'retired_at' => $now, 'updated_at' => $now,
                ]);
            }
            $permissions = $this->activeIds('pa_permission', $moduleKeys);
            $this->updateByIds('pa_permission', $permissions, ['status' => 'retired', 'retired_at' => $now, 'updated_at' => $now]);
            $this->updateByIds('pa_system_menu', $this->activeIds('pa_system_menu', $moduleKeys), [
                'status' => 'retired', 'updated_at' => $now,
                'upstream_defaults_json' => Db::raw("JSON_SET(upstream_defaults_json, '$.status', 'retired')"),
            ]);
            if ($invalidate) {
                app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization($moduleKeys);
            }
        });
    }

    /** @param list<string> $moduleKeys */
    public function purge(array $moduleKeys, bool $invalidate = true): void
    {
        \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        Db::transaction(function () use ($moduleKeys, $invalidate): void {
            if ($moduleKeys !== []) {
                Db::name('system_api')->whereIn('module_key', $moduleKeys)->where('status', 'active')->update([
                    'status' => 'retired', 'retired_at' => $this->now(), 'updated_at' => $this->now(),
                ]);
            }
            $permissions = $this->ids('pa_permission', $moduleKeys);
            $this->deleteByForeignIds('pa_role_permission', 'permission_id', $permissions);
            $this->deleteByForeignIds('pa_platform_role_permission', 'permission_id', $permissions);
            $this->deleteByIds('pa_system_menu', $this->ids('pa_system_menu', $moduleKeys));
            $this->deleteByForeignIds('pa_permission_api', 'permission_id', $permissions);
            $this->deleteByIds('pa_permission', $permissions);
            if ($invalidate) {
                app(ModuleAuthorizationCatalogSynchronizer::class)->invalidateTenantAuthorization($moduleKeys);
            }
        });
    }

    /** @return list<string> */
    private function ids(string $table, array $moduleKeys): array
    {
        return $moduleKeys === [] ? [] : array_map('strval', Db::table($table)->whereIn('module_key', $moduleKeys)->order('id')->column('id')); // @phpstan-ignore peanut.rawDbQuery (The private lifecycle caller supplies an owned catalog table and selected module keys.)
    }

    /** @return list<string> */
    private function activeIds(string $table, array $moduleKeys): array
    {
        return $moduleKeys === [] ? [] : array_map('strval', Db::table($table)->whereIn('module_key', $moduleKeys)->where('status', 'active')->order('id')->column('id')); // @phpstan-ignore peanut.rawDbQuery (The private retirement plan enumerates active global catalog rows of the selected modules.)
    }

    /** @return list<string> */
    private function foreignIds(string $table, string $column, array $ids): array
    {
        return $ids === [] ? [] : array_map('strval', Db::table($table)->whereIn($column, $ids)->order('id')->column('id')); // @phpstan-ignore peanut.rawDbQuery (The purge plan selects fixed catalog relations via IDs of the selected module permissions.)
    }

    /** @return list<string> */
    private function roleBindingIds(array $permissionIds, bool $platform): array
    {
        if ($permissionIds === []) {
            return [];
        }
        $table = $platform ? 'pa_platform_role_permission' : 'pa_role_permission';
        $fields = $platform ? 'platform_role_id,permission_id' : 'tenant_id,role_id,permission_id';
        $rows = Db::table($table)->whereIn('permission_id', $permissionIds)->field($fields)->select()->toArray(); // @phpstan-ignore peanut.rawDbQuery (Module-wide purge planning enumerates every role grant to the selected global permission IDs, including tenant grants.)
        $ids = array_map(static fn(array $row): string => $platform
            ? 'platform_role_id=' . $row['platform_role_id'] . ';permission_id=' . $row['permission_id']
            : 'tenant_id=' . $row['tenant_id'] . ';role_id=' . $row['role_id'] . ';permission_id=' . $row['permission_id'], $rows);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return list<array{code:string,identifiers:list<string>}> */
    private function crossModuleBlockers(array $moduleKeys, array $permissionIds, bool $purge): array
    {
        $checks = [];
        if ($moduleKeys !== [] && $permissionIds !== []) {
            $menu = Db::table('pa_system_menu');
            $hasInstancePermission = in_array('instance_permission_id', $menu->getTableFields(), true);
            $checks['MODULE_CATALOG_EXTERNAL_MENU_REFERENCE'] = $menu->whereNotIn('module_key', $moduleKeys)
                ->where(static function ($query) use ($permissionIds, $hasInstancePermission): void {
                    $query->whereIn('required_permission_id', $permissionIds);
                    if ($hasInstancePermission) {
                        $query->whereOr('instance_permission_id', 'in', $permissionIds);
                    }
                })
                ->order('id')->column('id');
            if ($purge) {
                $planPermissions = Db::name('plan_permission')->alias('selection')
                    ->join('permission permission', 'permission.key = selection.permission_key')
                    ->whereIn('permission.id', $permissionIds)
                    ->field('selection.plan_id,selection.permission_key')
                    ->order('selection.plan_id,selection.permission_key')->select()->toArray();
                $checks['MODULE_CATALOG_PLAN_PERMISSION_REFERENCE'] = array_map(
                    static fn(array $row): string => 'plan_id=' . $row['plan_id'] . ';permission_key=' . $row['permission_key'],
                    $planPermissions,
                );
            }
        }
        $blockers = [];
        foreach ($checks as $code => $ids) {
            if ($ids !== []) {
                $blockers[] = ['code' => $code, 'identifiers' => array_map('strval', $ids)];
            }
        }
        return $blockers;
    }

    private function append(array &$entries, string $table, string $action, array $ids, bool $includeEmpty = false): void
    {
        sort($ids, SORT_STRING);
        if ($ids !== [] || $includeEmpty) {
            $resource = match ($table) {
                'pa_system_api' => 'api-endpoints',
                'pa_permission_api' => 'permission-api-bindings',
                'pa_permission' => 'permissions',
                'pa_system_menu' => 'menu-definitions',
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
            Db::table($table)->whereIn('id', $ids)->update($values); // @phpstan-ignore peanut.rawDbQuery (The lifecycle updates only IDs already selected from module-owned global catalog definitions.)
        }
    }

    private function deleteByIds(string $table, array $ids): void
    {
        if ($ids !== []) {
            Db::table($table)->whereIn('id', $ids)->delete(); // @phpstan-ignore peanut.rawDbQuery (Purge deletes only IDs already selected from global definitions of the requested modules.)
        }
    }

    private function deleteByForeignIds(string $table, string $column, array $ids): void
    {
        if ($ids !== []) {
            Db::table($table)->whereIn($column, $ids)->delete(); // @phpstan-ignore peanut.rawDbQuery (Module purge removes dependent bindings only for the selected global permission IDs, across all affected tenants.)
        }
    }

    private function retireMissingKeys(string $table, string $moduleKey, array $activeKeys, string $now): void
    {
        $query = Db::table($table)->where('module_key', $moduleKey)->where('status', 'active'); // @phpstan-ignore peanut.rawDbQuery (Missing-key retirement targets the selected module's global permission catalog only.)
        if ($activeKeys !== []) {
            $query->whereNotIn('key', $activeKeys);
        }
        $values = ['status' => 'retired', 'updated_at' => $now];
        if ($table === 'pa_permission') {
            $values['retired_at'] = $now;
        }
        $query->update($values);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }
}
