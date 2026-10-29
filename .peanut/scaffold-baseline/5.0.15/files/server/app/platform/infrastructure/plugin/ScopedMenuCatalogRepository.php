<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Modules\Identity\Menu\MenuCatalogSynchronizer;

/** Preserves active menus outside a targeted module:sync/apply scope. */
final readonly class ScopedMenuCatalogRepository implements MenuCatalogRepository
{
    /** @param non-empty-list<string> $moduleKeys */
    public function __construct(
        private MenuCatalogRepository $inner,
        private array $moduleKeys,
    ) {}

    public function synchronize(MenuDefinition $definition, string $manifestDigest): void
    {
        $this->inner->synchronize($definition, $manifestDigest);
    }

    public function retireMissing(array $activeKeys): void
    {
        $preserved = (new MenuCatalogSynchronizer($this->inner))->activeKeysOutsideModules($this->moduleKeys);
        $keys = array_values(array_unique([...$activeKeys, ...$preserved]));
        sort($keys, SORT_STRING);
        $this->inner->retireMissing($keys);
    }

    public function activeDefinitions(string $scope): array
    {
        return $this->inner->activeDefinitions($scope);
    }

    public function activeDeploymentModules(): array
    {
        return $this->inner->activeDeploymentModules();
    }

    public function activeTenantModules(int $tenantId): array
    {
        return $this->inner->activeTenantModules($tenantId);
    }
}
