<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization\Persistence;

use DomainException;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use think\Model;

final class ThinkPhpAuthorizationCatalogRepository implements \PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository
{
    /** @var array<class-string<Model>,array<string,?array{id:int,module_key:string}>> */
    private array $prefetched = [];

    /** Load only the keys owned by this exact catalog synchronization, not all tenant/catalog rows.
     * @param class-string<Model> $model
     * @param list<string> $keys
     */
    public function prefetchKeys(string $model, array $keys): void
    {
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return;
        }
        $rows = array_fill_keys($keys, null);
        foreach ($model::whereIn('key', $keys)->field('id,key,module_key')->select()->toArray() as $row) {
            $key = (string) $row['key'];
            if (array_key_exists($key, $rows)) {
                $rows[$key] = ['id' => (int) $row['id'], 'module_key' => (string) $row['module_key']];
            }
        }
        $this->prefetched[$model] = array_replace($this->prefetched[$model] ?? [], $rows);
    }

    public function clearPrefetchedKeys(): void
    {
        $this->prefetched = [];
    }
    public function syncPermission(\PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition $definition): int
    {
        // Module synchronizers supply compiled, namespaced definitions; instance bundles retain their own owner.
        $unownedCustomKey = str_starts_with($definition->key, 'custom.')
            && (!str_starts_with($definition->moduleKey, 'custom.')
                || !str_starts_with($definition->key, $definition->moduleKey . '.'));
        if ($unownedCustomKey || str_starts_with($definition->key, 'platform.custom.')
            || str_starts_with($definition->moduleKey, 'instance.')) {
            throw new DomainException('The instance permission namespace is reserved.');
        }
        return $this->syncByKey(Permission::class, $definition->key, $definition->moduleKey, [
            'type' => $definition->type,
            'name' => $definition->name,
            'risk_level' => $definition->riskLevel,
            'status' => 'active',
            'manifest_version' => $definition->manifestVersion,
            'retired_at' => null,
        ]);
    }

    public function permissionId(string $key): int
    {
        return $this->idByKey(Permission::class, $key);
    }

    public function registryRevision(): string
    {
        $permissions = Permission::order('key')
            ->field('key,module_key,type,name,risk_level,status,manifest_version')
            ->select()->toArray();

        return hash('sha256', json_encode($permissions, JSON_THROW_ON_ERROR));
    }

    /** @param class-string<Model> $model
     * @param array<string, mixed> $data
     */
    private function syncByKey(string $model, string $key, string $moduleKey, array $data): int
    {
        $cached = array_key_exists($key, $this->prefetched[$model] ?? []);
        $row = $cached ? $this->prefetched[$model][$key]
            : $model::where('key', $key)->field('id,module_key')->find()?->toArray();
        if ($row !== null && (string) $row['module_key'] !== $moduleKey) {
            throw new DomainException('Catalog key is already owned by another module.');
        }
        $now = $this->now();
        if ($row === null) {
            $id = (int) $model::insertGetId([
                'key' => $key,
                'module_key' => $moduleKey,
                ...$data,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($cached) {
                $this->prefetched[$model][$key] = ['id' => $id, 'module_key' => $moduleKey];
            }
            return $id;
        }
        $model::where('id', (int) $row['id'])->update([...$data, 'updated_at' => $now]);

        return (int) $row['id'];
    }

    /** @param class-string<Model> $model */
    private function idByKey(string $model, string $key): int
    {
        $row = $this->prefetched[$model][$key] ?? null;
        $id = $row === null ? $model::where('key', $key)->value('id') : $row['id'];

        return $id === null ? throw new DomainException('Catalog entry was not found.') : (int) $id;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.000');
    }
}
