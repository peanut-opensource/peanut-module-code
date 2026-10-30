<?php

declare(strict_types=1);

namespace app\adminapi\services\setting;

use think\facade\Db;
use app\common\model\setting\HotSearch;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Kernel\Auth\TenantContext;
use app\common\exception\BusinessException;

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
        if (array_diff(array_keys($params), ['status', 'data']) !== []
            || !in_array($params['status'] ?? null, [0, 1, '0', '1'], true)
            || !is_array($params['data'] ?? null) || !array_is_list($params['data'])) {
            throw BusinessException::invalid('HOT_SEARCH_INPUT_INVALID', '热门搜索配置格式错误');
        }
        $rows = [];
        foreach ($params['data'] as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['id', 'name', 'sort']) !== []
                || !is_string($item['name'] ?? null)
                || trim($item['name']) === '' || mb_strlen($item['name']) > 200
                || (!is_int($item['sort'] ?? 0) && !is_string($item['sort'] ?? 0))
                || filter_var($item['sort'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]) === false) {
                throw BusinessException::invalid('HOT_SEARCH_INPUT_INVALID', '热门搜索词条格式错误');
            }
            $rows[] = ['name' => trim($item['name']), 'sort' => (int) ($item['sort'] ?? 0)];
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
