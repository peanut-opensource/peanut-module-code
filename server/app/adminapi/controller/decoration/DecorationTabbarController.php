<?php

declare(strict_types=1);

namespace app\adminapi\controller\decoration;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\decoration\DecorationTabbarApplicationService;
use app\adminapi\validate\decoration\DecorationTabbarValidate;

/** @property-read DecorationTabbarApplicationService $decorationTabbars 当前 App 中声明式解析的控制器依赖。 */
class DecorationTabbarController extends BaseAdminController
{
    protected string $decorationTabbarsClass = DecorationTabbarApplicationService::class;

    public function detail()
    {
        return $this->data($this->decorationTabbars->detail(
            $this->tenantAdminContext(),
        ));
    }

    public function save()
    {
        $params = $this->request->post();
        $this->validate($params, DecorationTabbarValidate::class);
        $this->decorationTabbars->save(
            $this->tenantAdminContext(),
            (array) $params['style'],
            (array) $params['list'],
        );
        return $this->success('保存成功');
    }
}
