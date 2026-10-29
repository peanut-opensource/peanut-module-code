<?php

declare(strict_types=1);

namespace app\adminapi\controller\system;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\system\SystemApplicationService;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\http\JsonResponseFactory;
use think\response\Json;

/**
 * 系统维护控制器
 * Class SystemController
 * @package app\adminapi\controller\system
 * @property-read SystemApplicationService $system 当前 App 中声明式解析的控制器依赖。
 */
class SystemController extends BaseAdminController
{
    protected string $systemClass = SystemApplicationService::class;

    /** 系统环境信息 */
    public function info()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }
        return $this->data($this->system->getInfo((string) $this->request->server('SERVER_SOFTWARE', '')));
    }

    /** 清除系统缓存 */
    public function clearCache()
    {
        $denial = $this->instanceToolAccessDenial();
        if ($denial !== null) {
            return $denial;
        }

        $this->system->clearCache();
        return $this->success('清除成功');
    }

    private function instanceToolAccessDenial(): ?Json
    {
        $guard = InstanceToolAccessGuard::fromConfiguredValue(config('deployment.mode'));
        return $guard->allows()
            ? null
            : throw \app\common\http\ApiProblem::fromEnvelope('实例级维护工具仅在 standalone 部署中可用', null, 40300);
    }
}
