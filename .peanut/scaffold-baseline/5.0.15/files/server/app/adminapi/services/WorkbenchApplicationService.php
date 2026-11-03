<?php

declare(strict_types=1);

namespace app\adminapi\services;

use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;

class WorkbenchApplicationService
{
    public function __construct(
        private readonly AdminAuthorizationService $authorization,
        private readonly FileReferences $files,
        private readonly WebsiteConfigService $website,
        private readonly string $projectVersion,
        private readonly string $projectBased,
        private readonly array $defaultImages,
        private readonly TenantSettingsQuery $settings,
    ) {}

    public function index(TenantContext $context): array
    {
        $website = $this->website->get($context);
        $demo = $this->settings->get($context, 'application.workbench-demo')->document;
        $available = ($demo['preset'] ?? null) === 'workbench';
        return [
            'version' => $this->versionInfo($context, $website),
            'statistics_state' => $available ? 'demo' : 'unavailable',
            'today'   => $available ? $demo['today'] : self::today(),
            'menu'    => self::menu($context),
            'visitor' => $available ? $demo['visitor'] : ['date' => [], 'list' => []],
            'support' => $this->support($context, $website),
            'sale'    => $available ? $demo['sale'] : ['date' => [], 'list' => []],
        ];
    }

    public function versionInfo(TenantContext $context, ?array $website = null): array
    {
        $website ??= $this->website->get($context);
        return [
            'version' => $this->projectVersion,
            'website' => $website['official_url'],
            'name'    => $website['name'],
            'based'   => $this->projectBased,
            'channel' => [
                'website' => $website['official_url'],
                'github'  => $website['github_url'],
            ],
        ];
    }

    public function today(): array
    {
        return [
            'time'           => '',
            'today_sales'    => 0,
            'total_sales'    => 0,
            'today_visitor'  => 0,
            'total_visitor'  => 0,
            'today_new_user' => 0,
            'total_new_user' => 0,
            'order_num'      => 0,
            'order_sum'      => 0,
        ];
    }

    public function menu(TenantContext $context): array
    {
        $items = [
            ['name' => '管理员', 'image' => 'menu_admin', 'url' => '/app/members'],
            ['name' => '角色管理', 'image' => 'menu_role', 'url' => '/app/roles'],
            ['name' => '部门管理', 'image' => 'menu_dept', 'url' => '/app/departments'],
            ['name' => '字典管理', 'image' => 'menu_dict', 'url' => '/system/dict'],
            ['name' => '素材中心', 'image' => 'menu_file', 'url' => '/system/file'],
            ['name' => '菜单权限', 'image' => 'menu_auth', 'url' => '/system/menu'],
            ['name' => '网站信息', 'image' => 'menu_web', 'url' => '/app-setting/website'],
        ];
        $authorizedMenus = $this->authorization->menusForAdminId($context, $context->memberId);
        $items = array_values(array_filter(
            $items,
            static fn(array $item): bool => self::menuContainsPath($authorizedMenus, $item['url']),
        ));

        return array_map(function (array $item): array {
            $item['image'] = $this->files->getFileUrl(
                (string) ($this->defaultImages[$item['image']] ?? ''),
            );
            return $item;
        }, $items);
    }

    /** @param list<array<string,mixed>> $menus */
    private static function menuContainsPath(array $menus, string $path): bool
    {
        foreach ($menus as $menu) {
            if (($menu['paths'] ?? null) === $path) {
                return true;
            }
            $children = $menu['children'] ?? [];
            if (is_array($children) && self::menuContainsPath($children, $path)) {
                return true;
            }
        }
        return false;
    }

    public function support(TenantContext $context, ?array $website = null): array
    {
        $website ??= $this->website->get($context);
        return [
            [
                'image' => $this->files->getFileUrl(
                    (string) ($this->defaultImages['project_docs'] ?? ''),
                ),
                'title' => '项目文档',
                'desc'  => '查看 Peanut Module Code 使用与开发文档',
                'url'   => (string) $website['official_url'],
            ],
            [
                'image' => $this->files->getFileUrl(
                    (string) ($this->defaultImages['technical_support'] ?? ''),
                ),
                'title' => '技术支持',
                'desc'  => '获取 Peanut Module Code 技术支持',
                'url'   => (string) $website['github_url'],
            ],
        ];
    }
}
