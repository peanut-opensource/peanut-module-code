<?php

declare(strict_types=1);

namespace app\api\controller;

use think\App;
use app\api\services\IndexApplicationService;

class IndexController extends BaseApiController
{
    public function __construct(
        App $app,
        private readonly IndexApplicationService $index,
    ) {
        parent::__construct($app);
    }


    /** 首页数据 */
    public function index()
    {
        $result = $this->index->getIndexData($this->publicTenantContext('article.index'));
        return $this->data($result);
    }

    /** 全局配置 */
    public function config()
    {
        $context = $this->publicTenantContext('decoration.config');
        $result = $this->index->getConfigData(
            $context,
            (string) $this->request->domain(),
            (string) $this->request->host(),
            $context->tenantId,
        );
        return $this->data($result);
    }

    /** 政策协议 */
    public function policy()
    {
        $type   = $this->request->get('type/s', 'service');
        $result = $this->index->getPolicyByType(
            $this->publicTenantContext('decoration.config'),
            $type,
        );
        return $this->data($result);
    }
}
