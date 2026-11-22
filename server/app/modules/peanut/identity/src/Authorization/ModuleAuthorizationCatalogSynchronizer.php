<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization;

use PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use think\facade\Db;

final readonly class ModuleAuthorizationCatalogSynchronizer
{
    public function __construct(private AuthorizationCatalogRepository $catalog) {}

    public function synchronize(CompiledModuleRegistry $registry): void
    {
        (new CorePermissionCatalogSynchronizer($this->catalog))->synchronize();

        try {
            if ($this->catalog instanceof ThinkPhpAuthorizationCatalogRepository) {
                $permissions = [...CorePermissionCatalog::TENANT, ...CorePermissionCatalog::PLATFORM];
                foreach ($registry->modules as $module) {
                    $moduleKey = $this->moduleString($module, 'key');
                    foreach ($this->catalogEntries($module, 'permissions') as $row) {
                        $permissions[] = $this->string($row, 'key', $moduleKey);
                    }
                }
                $this->catalog->prefetchKeys(Permission::class, $permissions);
            }
            foreach ($registry->modules as $module) {
                $this->synchronizePermissions($module);
            }
        } finally {
            if ($this->catalog instanceof ThinkPhpAuthorizationCatalogRepository) {
                $this->catalog->clearPrefetchedKeys();
            }
        }
    }

    /** Fixed catalog fingerprint fields, not credentials or a query builder.
     * @return list<array<string, mixed>>
     */
    public function revisionRows(): array
    {
        return Db::name('permission')
            ->field('id,key,module_key,status')
            ->fieldRaw('COALESCE(DATE_FORMAT(retired_at,"%Y-%m-%d %H:%i:%s.%f"),"") AS retired_at')
            ->order('id')->select()->toArray();
    }

    /** @param list<string> $moduleKeys */
    public function activeCount(array $moduleKeys): int
    {
        return $moduleKeys === [] ? 0 : (int) Db::table('pa_permission')
            ->whereIn('module_key', $moduleKeys)->where('status', 'active')->count();
    }

    /**
     * Invalidates affected authorization snapshots after an approved catalog change.
     * The deployment caller keeps lifecycle authorization and its enclosing transaction;
     * this advances revisions only and never grants a permission or enables a Module.
     * @param list<string> $moduleKeys
     */
    public function invalidateTenantAuthorization(array $moduleKeys): void
    {
        if ($moduleKeys === []) {
            return;
        }
        // Core, foundation and API catalog changes affect tenants without optional plan rows too.
        Db::name('tenant')->whereRaw('1 = 1')->update([
            'authorization_revision' => Db::raw('authorization_revision+1'),
            'revision' => Db::raw('revision+1'),
            'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    private function synchronizePermissions(ManifestDocument $module): void
    {
        $moduleKey = $this->moduleString($module, 'key');
        $version = $this->moduleString($module, 'version');
        foreach ($this->catalogEntries($module, 'permissions') as $permission) {
            $this->catalog->syncPermission(new PermissionDefinition(
                $this->string($permission, 'key', $moduleKey),
                $moduleKey,
                $this->string($permission, 'type', $moduleKey),
                $this->string($permission, 'name', $moduleKey),
                $this->string($permission, 'risk_level', $moduleKey),
                $version,
            ));
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogEntries(ManifestDocument $module, string $key): array
    {
        $catalog = $module->data['catalog'] ?? null;
        if (!is_array($catalog)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Compiled Module catalog is missing.');
        }

        return $this->entries($catalog, $key, $this->moduleString($module, 'key'));
    }

    /**
     * @param array<string, mixed> $owner
     * @return list<array<string, mixed>>
     */
    private function entries(array $owner, string $key, string $moduleKey): array
    {
        $entries = $owner[$key] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Catalog list {$key} is invalid in {$moduleKey}.");
        }
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "Catalog entry {$key} is invalid in {$moduleKey}.");
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $owner */
    private function string(array $owner, string $key, string $moduleKey): string
    {
        $value = $owner[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Catalog field {$key} is invalid in {$moduleKey}.");
        }

        return $value;
    }

    private function moduleString(ManifestDocument $module, string $key): string
    {
        return $this->string($module->data, $key, 'manifest');
    }

}
