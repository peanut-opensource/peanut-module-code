<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Menu;

use PeanutAdmin\Modules\Identity\Persistence\Model\MenuDefinition;
use think\facade\Db;

/** Instance-owned presentation management; module identity and permissions stay immutable. */
final class MenuAdministrationService
{
    /** @return list<array<string,mixed>> */
    public function records(string $scope = 'tenant'): array
    {
        return MenuDefinition::alias('menu')->leftJoin('permission p', 'p.id=menu.required_permission_id')
            ->where('menu.scope', $scope)->field('menu.*,p.key AS required_permission')
            ->order('menu.sort_order')->order('menu.key')->select()->toArray();
    }

    /** @param array<string,mixed> $presentation */
    public function updatePresentation(string $key, array $presentation): void
    {
        Db::transaction(function () use ($key, $presentation): void {
            $menu = MenuDefinition::where('key', $key)->where('scope', 'tenant')->lock(true)->find();
            if ($menu === null) {
                throw new \DomainException('ADMIN_MENU_NOT_FOUND');
            }
            $parentKey = $presentation['parent_key'] ?? null;
            $seen = [$key => true];
            while ($parentKey !== null) {
                if (isset($seen[$parentKey])) {
                    throw new \DomainException('ADMIN_MENU_PARENT_CYCLE');
                }
                $seen[$parentKey] = true;
                $parent = MenuDefinition::where('key', $parentKey)->where('scope', 'tenant')->lock(true)->find();
                if ($parent === null || $parent->getAttr('type') !== 'group') {
                    throw new \DomainException('ADMIN_MODULE_MENU_PARENT_INVALID');
                }
                $parentKey = $parent->getAttr('parent_key');
            }
            $menu->save([
                'name' => $presentation['name'], 'icon' => $presentation['icon'] ?? '',
                'sort_order' => -(int) ($presentation['sort'] ?? 0),
                'parent_key' => $presentation['parent_key'] ?? null,
                'is_show' => (int) ($presentation['is_show'] ?? 1),
                'is_cache' => (int) ($presentation['is_cache'] ?? 0),
                'is_disable' => (int) ($presentation['is_disable'] ?? 0),
            ]);
        });
    }
}
