<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Payment\Service\RechargeSettingApplicationService;
use PeanutAdmin\Modules\Payment\Validation\RechargeSettingValidate;

/** @property-read RechargeSettingApplicationService $rechargeSettings 当前 App 中声明式解析的控制器依赖。 */
class RechargeSettingController extends BaseAdminController
{
    protected string $rechargeSettingsClass = RechargeSettingApplicationService::class;

    public function config()
    {
        return $this->data($this->rechargeSettings->getConfig($this->tenantAdminContext()));
    }

    public function save()
    {
        $params = $this->request->post();
        $this->validate($params, RechargeSettingValidate::class . '.save');
        $this->rechargeSettings->save($this->tenantAdminContext(), $params);
        return $this->success('保存成功');
    }
}
