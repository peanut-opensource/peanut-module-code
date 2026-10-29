<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\OAuth\Service\WebPageApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\WebPageValidate;

/** @property-read WebPageApplicationService $webPages 当前 App 中声明式解析的控制器依赖。 */
class WebPageController extends BaseAdminController
{
    protected string $webPagesClass = WebPageApplicationService::class;

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
