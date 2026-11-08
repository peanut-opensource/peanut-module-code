<?php

declare(strict_types=1);

namespace app\adminapi\controller\config;

use app\adminapi\controller\BaseAdminController;
use app\common\services\readiness\FirstRunReadinessHost;
use think\response\Json;

/** @property-read FirstRunReadinessHost $readiness 当前 App 中声明式解析的控制器依赖。 */
final class ReadinessController extends BaseAdminController
{
    protected string $readinessClass = FirstRunReadinessHost::class;

    public function checklist(): Json
    {
        return $this->data($this->readiness->checklist(
            $this->tenantAdminContext(),
            (string) $this->request->domain(),
            (string) config('deployment.mode'),
        ));
    }
}
