<?php

declare(strict_types=1);

namespace app\adminapi\controller\system;

use think\App;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\system\SystemApplicationService;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\http\JsonResponseFactory;
use think\response\Json;

/**
 * 系统维护控制器
 * Class SystemController
 * @package app\adminapi\controller\system
 */
class SystemController extends BaseAdminController
{
    public function __construct(App $app, protected readonly SystemApplicationService $system)
    {
        parent::__construct($app);
    }
    /** 系统环境信息 */
    public function info()
    {
        $this->assertInstanceToolAccess();
        return $this->data($this->system->getInfo((string) $this->request->server('SERVER_SOFTWARE', '')));
    }

    /** 清除系统缓存 */
    public function clearCache()
    {
        $this->assertInstanceToolAccess();

        $this->system->clearCache();
        return $this->success('清除成功');
    }

    private function assertInstanceToolAccess(): void
    {
        $guard = InstanceToolAccessGuard::fromConfiguredValue(config('deployment.mode'));
        if (!$guard->allows()) {
            throw \app\common\http\ApiProblem::fromEnvelope('实例级维护工具仅在 standalone 部署中可用', null, 40300);
        }
    }
}
