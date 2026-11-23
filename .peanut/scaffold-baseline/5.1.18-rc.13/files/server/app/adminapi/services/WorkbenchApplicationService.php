<?php

declare(strict_types=1);

namespace app\adminapi\services;

use app\common\services\authorization\AdminAuthorizationService;
use app\common\validation\instance\InstanceToolAccessGuard;
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
            ['name' => '管理员', 'image' => 'menu_admin', 'url' => '/app/members', 'permission' => 'core.member.read'],
            ['name' => '角色管理', 'image' => 'menu_role', 'url' => '/app/roles', 'permission' => 'core.role.read'],
            ['name' => '部门管理', 'image' => 'menu_dept', 'url' => '/app/departments', 'permission' => 'core.department.read'],
            ['name' => '字典管理', 'image' => 'menu_dict', 'url' => '/system/dict', 'permission' => 'peanut.reference-codes.read'],
            ['name' => '素材中心', 'image' => 'menu_file', 'url' => '/system/file', 'permission' => 'peanut.file.list'],
            ['name' => '菜单权限', 'image' => 'menu_auth', 'url' => '/system/menu', 'permission' => 'core.menu.read'],
            ['name' => '网站信息', 'image' => 'menu_web', 'url' => '/app-setting/website', 'permission' => 'peanut.settings.read'],
        ];
        $granted = array_flip($this->authorization->grantedPermissions($context, array_column($items, 'permission')));
        $allowInstanceTools = InstanceToolAccessGuard::fromConfiguredValue(config('deployment.mode'))->allows();
        $items = array_values(array_map(
            static fn(array $item): array => [
                'name' => $item['name'],
                'image' => $item['image'],
                'url' => $item['url'],
            ],
            array_filter(
                $items,
                static fn(array $item): bool => isset($granted[$item['permission']])
                    && ($item['url'] !== '/system/menu' || $allowInstanceTools),
            ),
        ));

        $imageRefs = [];
        foreach ($items as $item) {
            $ref = trim((string) ($this->defaultImages[$item['image']] ?? ''));
            if ($ref !== '') {
                $imageRefs[] = $ref;
            }
        }
        $resolvedUrls = $imageRefs === [] ? [] : $this->files->getFileUrls($imageRefs);

        return array_map(function (array $item) use ($resolvedUrls): array {
            $ref = trim((string) ($this->defaultImages[$item['image']] ?? ''));
            $item['image'] = $ref === '' ? '' : ($resolvedUrls[$ref] ?? '');
            return $item;
        }, $items);
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
