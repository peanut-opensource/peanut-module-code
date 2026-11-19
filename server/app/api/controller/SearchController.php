<?php

declare(strict_types=1);

namespace app\api\controller;

use think\App;

use app\api\services\SearchApplicationService;

class SearchController extends BaseApiController
{
    public function __construct(App $app, protected readonly SearchApplicationService $search)
    {
        parent::__construct($app);
    }
    /** 热门搜索 */
    public function hotLists()
    {
        $result = $this->search->hotLists(
            $this->publicTenantContext('hot-search.lists'),
        );
        return $this->data($result);
    }
}
