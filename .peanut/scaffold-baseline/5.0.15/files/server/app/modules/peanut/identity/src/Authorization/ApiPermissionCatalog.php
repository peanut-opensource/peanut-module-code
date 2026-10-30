<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use think\facade\Db;

/** Instance API catalogue and independent permission-bundle associations. */
final class ApiPermissionCatalog
{
    public function moduleAvailable(array $endpoint, \app\common\contract\module\ModuleQualificationQuery $modules, ?int $tenantId = null): bool
    {
        $owner = $endpoint['module_key'];
        if (in_array($owner, ['peanut.admin', 'platform', 'member', 'installation'], true)) {
            return true;
        }
        $installed = array_column(array_map(static fn($module): array => ['key' => $module->moduleKey], $modules->installedModules()), 'key');
        return in_array($owner, $installed, true)
            && ($tenantId === null || in_array($owner, $modules->activeTenantModuleKeys($tenantId), true));
    }

    /** The route generator is the only discovery producer. No runtime scanner or role grants. */
    public function synchronize(array $catalog, array $moduleKeys, bool $fullRegistry): void
    {
        if (($catalog['schema_version'] ?? null) !== 1 || !is_array($catalog['endpoints'] ?? null)) {
            throw new \DomainException('API_CATALOG_INVALID');
        }
        $now = gmdate('Y-m-d H:i:s');
        $activeIds = [];
        foreach ($catalog['endpoints'] as $endpoint) {
            $owner = $endpoint['owner']['type'] === 'module'
                ? (string) $endpoint['owner']['key']
                : match ($endpoint['application']) {
                    'adminapi' => 'peanut.admin', 'platform' => 'platform', 'api' => 'member', default => 'installation',
                };
            if ($endpoint['owner']['type'] === 'module' && !in_array($owner, $moduleKeys, true)) {
                continue;
            }
            $audience = (string) ($endpoint['audience'] ?? '');
            $id = (string) ($endpoint['api_id'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/D', $id) || !in_array($audience, ['tenant', 'platform', 'member', 'installation'], true)) {
                throw new \DomainException('API_CATALOG_REGENERATION_REQUIRED');
            }
            $defaults = $endpoint['default_permission_keys'];
            $mode = (string) $endpoint['auth']['mode'];
            $data = ['method' => $endpoint['method'], 'path' => strtolower($endpoint['path']), 'audience' => $audience,
                'module_key' => $owner, 'access_mode' => $mode, 'status' => 'active',
                'default_permission_keys' => json_encode($defaults, JSON_THROW_ON_ERROR),
                'prerequisite_permission_keys' => json_encode($endpoint['prerequisite_permission_keys'], JSON_THROW_ON_ERROR),
                'updated_at' => $now, 'retired_at' => null];
            Db::name('api_endpoint')->duplicate($data)->insert(['id' => $id, ...$data, 'created_at' => $now]);
            $activeIds[] = $id;
            if (!in_array($mode, ['tenant_permission', 'platform_permission'], true)) {
                $defaults = [];
            }
            $desired = [];
            foreach ($defaults as $key) {
                $permission = Db::name('permission')->where('key', $key)->where('status', 'active')->lock(true)->find();
                if ($permission === null || self::permissionAudience($permission) !== $audience) {
                    continue;
                }
                $permissionId = (int) $permission['id'];
                $desired[] = $permissionId;
                Db::name('permission_api_config')->duplicate(['permission_id'])->insert([
                    'permission_id' => $permissionId, 'revision' => 1, 'configured' => 0, 'updated_at' => $now,
                ]);
                $config = Db::name('permission_api_config')->where('permission_id', $permissionId)->lock(true)->find();
                if ((int) $config['configured'] === 0) {
                    $exists = Db::name('permission_api')->where('permission_id', $permissionId)->where('api_id', $id)->value('id');
                    if ($exists === null) {
                        Db::name('permission_api')->insert(['permission_id' => $permissionId, 'api_id' => $id, 'created_at' => $now]);
                        Db::name('permission_api_config')->where('permission_id', $permissionId)->inc('revision')->update(['updated_at' => $now]);
                    }
                }
            }
            // Source-default sets follow the current declaration; explicit instance sets never do.
            $obsolete = Db::name('permission_api')->alias('binding')
                ->join('permission_api_config config', 'config.permission_id=binding.permission_id')
                ->where('binding.api_id', $id)->where('config.configured', 0);
            if ($desired !== []) {
                $obsolete->whereNotIn('binding.permission_id', $desired);
            }
            foreach ($obsolete->field('binding.id,binding.permission_id')->lock(true)->select()->toArray() as $binding) {
                Db::name('permission_api')->where('id', $binding['id'])->delete();
                Db::name('permission_api_config')->where('permission_id', $binding['permission_id'])->inc('revision')->update(['updated_at' => $now]);
            }
        }
        $retire = Db::name('api_endpoint')->where('status', 'active');
        if (!$fullRegistry) {
            $retire->whereIn('module_key', ['peanut.admin', 'platform', 'member', 'installation', ...$moduleKeys]);
        }
        if ($activeIds !== []) {
            $retire->whereNotIn('id', $activeIds);
        }
        $retire->update(['status' => 'retired', 'retired_at' => $now, 'updated_at' => $now]);
    }

    public function revisionRows(): array
    {
        return [
            'endpoints' => Db::name('api_endpoint')->field('id,method,path,audience,module_key,access_mode,status,default_permission_keys,prerequisite_permission_keys')->order('id')->select()->toArray(),
            'bindings' => Db::name('permission_api')->field('permission_id,api_id')->order('permission_id,api_id')->select()->toArray(),
        ];
    }

    public static function permissionAudience(array $permission): string
    {
        return $permission['module_key'] === 'platform' || str_starts_with((string) $permission['key'], 'platform.') ? 'platform' : 'tenant';
    }

    /** Native matched Rule identifies the template; never derive authority from submitted IDs. */
    public function endpoint(\think\Request $request, string $audience): ?array
    {
        $rule = $request->rule()?->getRule();
        if (!is_string($rule) || $rule === '') {
            return null;
        }
        $template = preg_replace('/<([A-Za-z_][A-Za-z0-9_]*)>/', '{$1}', $rule);
        $prefix = $audience === 'platform' ? 'platformapi' : 'adminapi';
        return Db::name('api_endpoint')->where('method', strtoupper($request->method()))
            ->where('path', '/' . $prefix . '/' . strtolower(trim($template, '/')))
            ->where('audience', $audience)->where('status', 'active')->find();
    }

    /** @return list<string> */
    public function permissionKeys(array $endpoint, bool $lock = false): array
    {
        $query = Db::name('permission_api')->alias('binding')
            ->join('permission permission', 'permission.id=binding.permission_id')
            ->where('binding.api_id', $endpoint['id'])->where('permission.status', 'active')
            ->where(function ($query) use ($endpoint): void {
                if ($endpoint['audience'] === 'platform') {
                    $query->whereLike('permission.key', 'platform.%');
                } else {
                    $query->whereNotLike('permission.key', 'platform.%')->where('permission.module_key', '<>', 'platform');
                }
            })->order('permission.key');
        if ($lock) {
            $query->lock(true);
        }
        return array_values($query->column('permission.key'));
    }

    public function bundles(PageRequest $page, string $audience, string $search): array
    {
        $query = Db::name('permission');
        if ($audience === 'platform') {
            $query->whereLike('key', 'platform.%');
        } else {
            $query->whereNotLike('key', 'platform.%')->where('module_key', '<>', 'platform');
        }
        if ($search !== '') {
            $query->whereLike('key|name|module_key', '%' . $search . '%');
        }
        $total = (int) (clone $query)->count();
        $rows = $query->order('module_key,key')->limit($page->offset(), $page->pageSize)->select()->toArray();
        $ids = array_column($rows, 'id');
        $configs = $ids === [] ? [] : array_column(Db::name('permission_api_config')->whereIn('permission_id', $ids)->select()->toArray(), null, 'permission_id');
        $bindings = [];
        if ($ids !== []) {
            foreach (Db::name('permission_api')->whereIn('permission_id', $ids)->order('api_id')->select()->toArray() as $binding) {
                $bindings[$binding['permission_id']][] = $binding['api_id'];
            }
        }
        return ['items' => array_map(fn(array $row): array => $this->bundleRow($row, $configs[$row['id']] ?? [], $bindings[$row['id']] ?? []), $rows), 'total' => $total];
    }

    public function apis(PageRequest $page, string $audience, string $search, string $status): array
    {
        $query = Db::name('api_endpoint')->where('audience', $audience);
        if ($search !== '') {
            $query->whereLike('method|path|module_key', '%' . $search . '%');
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        $total = (int) (clone $query)->count();
        $rows = $query->field('id,method,path,audience,module_key,access_mode,status,default_permission_keys,prerequisite_permission_keys')
            ->order('path,method')->limit($page->offset(), $page->pageSize)->select()->toArray();
        foreach ($rows as &$row) {
            $row['default_permission_keys'] = json_decode($row['default_permission_keys'], true, 32, JSON_THROW_ON_ERROR);
            $row['prerequisite_permission_keys'] = json_decode($row['prerequisite_permission_keys'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return ['items' => array_values($rows), 'total' => $total];
    }

    public function bundle(string $key): array
    {
        $permission = Db::name('permission')->where('key', $key)->find();
        if ($permission === null) {
            throw AdminAccessException::notFound();
        }
        return $this->bundleRow($permission);
    }

    public function replace(string $key, int $expectedRevision, array $apiIds, \app\common\contract\module\ModuleQualificationQuery $modules): array
    {
        return Db::transaction(function () use ($key, $expectedRevision, $apiIds, $modules): array {
            // Serialize only this management dependency's invariant through native row locks.
            $recoveryApis = Db::name('api_endpoint')->where('audience', 'platform')->where('status', 'active')
                ->where(function ($query): void {
                    $query->where(function ($read): void {
                        $read->where('method', 'GET')->whereIn('path', [
                            '/platformapi/permission/bundles', '/platformapi/permission/apis', '/platformapi/permission/apis/bindings',
                        ]);
                    })->whereOr(function ($write): void {
                        $write->where('method', 'PUT')->where('path', '/platformapi/permission/apis/bindings');
                    })->whereOr(function ($write): void {
                        $write->whereIn('method', ['POST', 'PUT'])->where('path', '/platformapi/permission/bundles');
                    });
                })->order('id')->lock(true)->select()->toArray();
            $permission = Db::name('permission')->where('key', $key)->lock(true)->find();
            if ($permission === null) {
                throw AdminAccessException::notFound();
            }
            if ($permission['status'] !== 'active') {
                throw AdminAccessException::invalid('PERMISSION_RETIRED', 'Retired bundles cannot be edited.');
            }
            $current = $this->bundleRow($permission);
            if ((int) $current['revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            $apiIds = array_values(array_unique($apiIds));
            $rows = $apiIds === [] ? [] : Db::name('api_endpoint')->whereIn('id', $apiIds)->lock(true)->select()->toArray();
            if (count($rows) !== count($apiIds)) {
                throw AdminAccessException::invalid('API_NOT_BINDABLE', 'All APIs must be registered.');
            }
            foreach ($rows as $row) {
                $existing = in_array($row['id'], $current['api_ids'], true);
                if ($row['audience'] !== $current['audience']
                    || $row['access_mode'] !== $current['audience'] . '_permission'
                    || (!$existing && ($row['status'] !== 'active' || !$this->moduleAvailable($row, $modules)))) {
                    throw AdminAccessException::invalid('API_NOT_BINDABLE', 'Only protected APIs in the same audience can be newly bound.');
                }
            }
            $now = gmdate('Y-m-d H:i:s');
            Db::name('permission_api')->where('permission_id', $permission['id'])->delete();
            if ($apiIds !== []) {
                Db::name('permission_api')->insertAll(array_map(static fn(string $id): array => [
                    'permission_id' => $permission['id'], 'api_id' => $id, 'created_at' => $now,
                ], $apiIds));
            }
            Db::name('permission_api_config')->duplicate(['revision' => $expectedRevision + 1, 'configured' => 1, 'updated_at' => $now])->insert([
                'permission_id' => $permission['id'], 'revision' => $expectedRevision + 1, 'configured' => 1, 'updated_at' => $now,
            ]);
            // These exact routes are the UI's repair path. Empty ordinary bundles remain valid.
            foreach ($recoveryApis as $api) {
                if ($this->permissionKeys($api, true) === []) {
                    throw AdminAccessException::conflict('API_MANAGEMENT_RECOVERY_REQUIRED', 'Keep an active platform bundle bound to the API mapping management routes.');
                }
            }
            return $this->bundle($key);
        });
    }

    public function createCustom(string $key, string $name, string $audience): array
    {
        $prefix = $audience === 'platform' ? 'platform.custom.' : 'custom.';
        if (!str_starts_with($key, $prefix) || preg_match('/^[a-z][a-z0-9._-]{1,99}$/D', $key) !== 1 || strlen($key) <= strlen($prefix)) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'Custom permission keys must use the reserved custom namespace.');
        }
        if (Db::name('permission')->where('key', $key)->find() !== null) {
            throw AdminAccessException::conflict('PERMISSION_KEY_EXISTS', 'The permission key already exists.');
        }
        $now = gmdate('Y-m-d H:i:s');
        Db::name('permission')->insert(['key' => $key, 'module_key' => 'instance.' . $audience,
            'type' => 'api', 'name' => $name, 'risk_level' => 'normal', 'status' => 'active',
            'manifest_version' => 'instance', 'created_at' => $now, 'updated_at' => $now]);
        return $this->bundle($key);
    }

    public function updateCustom(string $key, string $name, string $status, int $revision): array
    {
        $row = Db::name('permission')->where('key', $key)->lock(true)->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        if (!in_array($row['module_key'], ['instance.tenant', 'instance.platform'], true)) {
            throw AdminAccessException::invalid('PERMISSION_NOT_CUSTOM', 'Only custom bundles can be edited.');
        }
        $current = $this->bundleRow($row);
        if ((int) $current['revision'] !== $revision) {
            throw AdminAccessException::revisionMismatch();
        }
        $now = gmdate('Y-m-d H:i:s');
        Db::name('permission')->where('id', $row['id'])->update(['name' => $name, 'status' => $status,
            'retired_at' => $status === 'retired' ? $now : null, 'updated_at' => $now]);
        Db::name('permission_api_config')->duplicate(['revision' => $revision + 1, 'updated_at' => $now])->insert([
            'permission_id' => $row['id'], 'revision' => $revision + 1, 'configured' => 1, 'updated_at' => $now,
        ]);
        if ($row['status'] !== $status) {
            $roleIds = Db::name('platform_role_permission')->where('permission_id', $row['id'])->column('platform_role_id');
            if ($roleIds !== []) {
                Db::name('platform_role')->whereIn('id', $roleIds)->inc('revision')->update(['updated_at' => $now]);
            }
            $tenantIds = Db::name('role_permission')->where('permission_id', $row['id'])->distinct(true)->column('tenant_id');
            if ($tenantIds !== []) {
                Db::name('tenant')->whereIn('id', $tenantIds)->inc('authorization_revision')->inc('revision')->update(['updated_at' => $now]);
            }
        }
        if ($status === 'retired') {
            $this->assertManagementRecovery();
        }
        return $this->bundle($key);
    }

    private function assertManagementRecovery(): void
    {
        $apis = Db::name('api_endpoint')->where('audience', 'platform')->where('status', 'active')
            ->whereIn('path', ['/platformapi/permission/bundles', '/platformapi/permission/apis', '/platformapi/permission/apis/bindings'])
            ->lock(true)->select()->toArray();
        foreach ($apis as $api) {
            if ($this->permissionKeys($api, true) === []) {
                throw AdminAccessException::conflict('API_MANAGEMENT_RECOVERY_REQUIRED', 'Keep an active platform bundle bound to the management routes.');
            }
        }
    }

    private function bundleRow(array $row, ?array $config = null, ?array $apiIds = null): array
    {
        $config ??= Db::name('permission_api_config')->where('permission_id', $row['id'])->find();
        $apiIds ??= array_values(Db::name('permission_api')->where('permission_id', $row['id'])->order('api_id')->column('api_id'));
        return ['key' => $row['key'], 'name' => $row['name'], 'module_key' => $row['module_key'],
            'source' => str_starts_with($row['module_key'], 'instance.') ? 'custom' : (in_array($row['module_key'], ['core', 'peanut.admin', 'platform'], true) ? 'system' : 'module'),
            'audience' => self::permissionAudience($row), 'status' => $row['status'],
            'revision' => (string) ($config['revision'] ?? 1), 'configured' => (bool) ($config['configured'] ?? false),
            'api_ids' => $apiIds];
    }
}
