<?php

declare(strict_types=1);

namespace app\adminapi\controller\decoration;

use think\App;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\decoration\DecorationTabbarApplicationService;
use app\adminapi\validate\decoration\DecorationTabbarValidate;

class DecorationTabbarController extends BaseAdminController
{
    public function __construct(App $app, protected readonly DecorationTabbarApplicationService $decorationTabbars)
    {
        parent::__construct($app);
    }
    public function detail()
    {
        return $this->data($this->decorationTabbars->detail(
            $this->tenantAdminContext(),
        ));
    }

    public function save()
    {
        $params = $this->validateInput($this->request->post(), DecorationTabbarValidate::class, ['style', 'list'])->writable();
        $this->decorationTabbars->save(
            $this->tenantAdminContext(),
            (array) $params['style'],
            (array) $params['list'],
        );
        return $this->success('保存成功');
    }
}
