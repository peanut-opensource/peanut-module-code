<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Menu;

use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\facade\Db;

final readonly class MenuCatalogSynchronizer
{
    public function __construct(private \PeanutAdmin\Kernel\Menu\MenuCatalogRepository $repository) {}

    public function synchronize(CompiledModuleRegistry $registry): void
    {
        $definitions = CoreMenuCatalog::definitions();
        foreach ($registry->menus as $menu) {
            $definitions[] = $this->definition($menu);
        }
        foreach ($definitions as $definition) {
            $this->repository->synchronize($definition, $registry->revision);
        }
        $this->repository->retireMissing(array_map(
            static fn(\PeanutAdmin\Kernel\Menu\MenuDefinition $definition): string => $definition->key,
            $definitions,
        ));
    }

    /** Fixed definition metadata for deployment catalog fingerprints, not user menu access.
     * @return list<array<string, mixed>>
     */
    public function revisionRows(): array
    {
        return Db::name('menu_definition')
            ->field('id,key,module_key,status,manifest_digest')->order('id')->select()->toArray();
    }

    /** @param list<string> $moduleKeys */
    public function activeCount(array $moduleKeys): int
    {
        return $moduleKeys === [] ? 0 : (int) Db::table('pa_menu_definition')
            ->whereIn('module_key', $moduleKeys)->where('status', 'active')->count();
    }

    /** 定向目录同步时保留非目标模块的活动菜单键，不返回用户权限或菜单私有数据。
     * @param list<string> $moduleKeys
     * @return list<string>
     */
    public function activeKeysOutsideModules(array $moduleKeys): array
    {
        return array_map('strval', Db::name('menu_definition')->where('status', 'active')
            ->whereNotIn('module_key', $moduleKeys)->order('key')->column('key'));
    }

    /** @param array<string, mixed> $menu */
    private function definition(array $menu): \PeanutAdmin\Kernel\Menu\MenuDefinition
    {
        if (($menu['scope'] ?? null) === 'tenant' && ($menu['type'] ?? null) === 'page'
            && !array_key_exists('parent_key', $menu)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Tenant page menus must declare a category parent_key; explicit null reserves an intentional root entry.');
        }
        $clients = $menu['client_keys'] ?? null;
        if (!is_array($clients) || !array_is_list($clients)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Menu client keys are invalid.');
        }

        return new \PeanutAdmin\Kernel\Menu\MenuDefinition(
            $this->requiredString($menu, 'key'),
            $this->requiredString($menu, 'module_key'),
            $this->requiredString($menu, 'scope'),
            $this->optionalString($menu, 'parent_key'),
            $this->requiredString($menu, 'type'),
            $this->requiredString($menu, 'name'),
            $this->optionalString($menu, 'route_name'),
            $this->optionalString($menu, 'route_path'),
            $this->optionalString($menu, 'component_key'),
            $this->optionalString($menu, 'required_permission'),
            array_map('strval', $clients),
            (int) ($menu['sort_order'] ?? 0),
            $this->optionalString($menu, 'icon'),
        );
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Menu field {$key} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', "Menu field {$key} is invalid.");
        }

        return $value;
    }
}
