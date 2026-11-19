<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Payment\Service\RechargeSettingApplicationService;
use PeanutAdmin\Modules\Payment\Validation\RechargeSettingValidate;
use think\App;

class RechargeSettingController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly RechargeSettingApplicationService $rechargeSettings,
    ) {
        parent::__construct($app);
    }

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
