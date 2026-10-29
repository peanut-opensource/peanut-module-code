<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Payment\Service\PayConfigApplicationService;
use PeanutAdmin\Modules\Payment\Validation\PayConfigValidate;

/** @property-read PayConfigApplicationService $payConfigs 当前 App 中声明式解析的控制器依赖。 */
class PayConfigController extends BaseAdminController
{
    protected string $payConfigsClass = PayConfigApplicationService::class;

    public function getConfig()
    {
        return $this->data($this->payConfigs->getConfig($this->tenantAdminContext()));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, PayConfigValidate::class);
        $this->payConfigs->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
