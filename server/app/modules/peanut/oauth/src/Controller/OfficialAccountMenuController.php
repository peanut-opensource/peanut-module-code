<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\OAuth\Service\OfficialAccountMenuApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\OfficialAccountMenuValidate;

/** @property-read OfficialAccountMenuApplicationService $officialAccountMenus 当前 App 中声明式解析的控制器依赖。 */
class OfficialAccountMenuController extends BaseAdminController
{
    protected string $officialAccountMenusClass = OfficialAccountMenuApplicationService::class;

    public function detail()
    {
        return $this->data($this->officialAccountMenus->detail($this->tenantAdminContext()));
    }

    public function save()
    {
        $params = $this->request->post();
        $this->validate($params, OfficialAccountMenuValidate::class);
        $this->officialAccountMenus->save(
            $this->tenantAdminContext(),
            (array) $params['menu'],
        );
        return $this->success('保存成功');
    }

    public function saveAndPublish()
    {
        $params = $this->request->post();
        $this->validate($params, OfficialAccountMenuValidate::class);
        $this->officialAccountMenus->saveAndPublish(
            $this->tenantAdminContext(),
            (array) $params['menu'],
        );
        return $this->success('发布成功');
    }
}
