<?php

declare(strict_types=1);

namespace app\adminapi\services\setting;

use think\facade\Db;
use app\common\model\setting\HotSearch;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Kernel\Auth\TenantContext;

/**
 * 热门搜索设置 Logic
 *
 * - 开关：pa_tenant_setting namespace=hot-search（0关 1开）
 * - 词条：pa_hot_search（Tenant-owned name + sort），当前 Tenant「全删再全建」保存
 */
class HotSearchApplicationService
{
    protected const CONFIG_TYPE = 'hot_search';

    public function __construct(
        private readonly TenantApplicationSettings $applicationSettings,
    ) {}

    /** 读取配置：开关 + 词条列表 */
    public function getConfig(TenantContext $context): array
    {
        return [
            'status' => (int) $this->applicationSettings->hotSearch($context)['status'],
            'data'   => HotSearch::where([])
                ->field(['id', 'name', 'sort'])
                ->order(['sort' => 'desc', 'id' => 'desc'])
                ->select()
                ->toArray(),
        ];
    }

    /**
     * 保存配置：写开关 + 全量替换词条
     * @param array<string,mixed> $params
     */
    public function setConfig(TenantContext $context, array $params): bool
    {
        $rows = [];
        foreach ((array) ($params['data'] ?? []) as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rows[] = ['name' => $name, 'sort' => (int) ($item['sort'] ?? 0)];
        }

        return Db::transaction(function () use ($context, $params, $rows): bool {
            $this->applicationSettings->replaceHotSearch($context, [
                'status' => (int) ($params['status'] ?? 0),
            ]);
            HotSearch::where([])->delete();
            if ($rows !== []) {
                (new HotSearch())->saveAll($rows);
            }
            return true;
        });
    }
}
