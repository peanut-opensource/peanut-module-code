<?php

declare(strict_types=1);

namespace app\api\controller;

use think\App;
use app\api\services\PcApplicationService;

/**
 * PC 端聚合接口（部分端点返回更丰富的字段或不同格式）
 */
class PcController extends BaseApiController
{
    public function __construct(
        App $app,
        private readonly PcApplicationService $pcApplication,
    ) {
        parent::__construct($app);
    }


    /** PC 配置 */
    public function config()
    {
        $context = $this->publicTenantContext('decoration.config');
        $result = $this->pcApplication->config(
            $context,
            (string) $this->request->domain(),
            (string) $this->request->host(),
            $context->tenantId,
        );
        return $this->data($result);
    }

}
