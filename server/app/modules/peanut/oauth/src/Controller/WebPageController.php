<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\OAuth\Service\WebPageApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\WebPageValidate;
use think\App;

class WebPageController extends BaseAdminController
{
    public function __construct(
        App $app,
        private readonly WebPageApplicationService $webPages,
    ) {
        parent::__construct($app);
    }

    public function getConfig()
    {
        return $this->data($this->webPages->getConfig(
            $this->tenantAdminContext(),
            (string) $this->request->domain(),
        ));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, WebPageValidate::class);
        $this->webPages->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
