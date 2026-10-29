<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\OAuth\Service\OpenPlatformApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\OpenPlatformValidate;

/** @property-read OpenPlatformApplicationService $openPlatforms 当前 App 中声明式解析的控制器依赖。 */
class OpenPlatformController extends BaseAdminController
{
    protected string $openPlatformsClass = OpenPlatformApplicationService::class;

    public function getConfig()
    {
        return $this->data($this->openPlatforms->getConfig($this->tenantAdminContext()));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, OpenPlatformValidate::class);
        $this->openPlatforms->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
