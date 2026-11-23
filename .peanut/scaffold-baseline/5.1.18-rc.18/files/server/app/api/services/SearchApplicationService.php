<?php

declare(strict_types=1);

namespace app\api\services;

use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use app\common\model\setting\HotSearch;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

class SearchApplicationService
{
    public function __construct(private readonly TenantApplicationSettings $applicationSettings) {}

    /** 热门搜索列表 */
    public function hotLists(TenantContext|TenantSystemContext $context): array
    {
        $data = HotSearch::where([])
            ->field(['name', 'sort'])
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();

        return [
            'status' => (int) $this->applicationSettings->hotSearch($context)['status'],
            'data'   => $data,
        ];
    }
}
