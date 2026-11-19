<?php

declare(strict_types=1);

namespace app\api\controller;

use app\api\services\SearchApplicationService;

/** @property-read SearchApplicationService $search 当前 App 中声明式解析的控制器依赖。 */
class SearchController extends BaseApiController
{
    protected string $searchClass = SearchApplicationService::class;


    /** 热门搜索 */
    public function hotLists()
    {
        $result = $this->search->hotLists(
            $this->publicTenantContext('hot-search.lists'),
        );
        return $this->data($result);
    }
}
