<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use think\App;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\auth\MenuApplicationService;
use app\adminapi\validate\auth\MenuValidate;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\http\JsonResponseFactory;
use think\response\Json;

class MenuController extends BaseAdminController
{
    public function __construct(App $app, protected readonly MenuApplicationService $menus)
    {
        parent::__construct($app);
    }
    public function route()
    {
        return $this->data($this->menus->getMenuByAdminId($this->executionContext()->tenantAdmin(), $this->adminId));
    }
    public function lists()
    {
        $this->assertInstanceToolAccess();
        return $this->data($this->menus->getAll());
    }
    public function all()
    {
        return $this->data($this->menus->getAllSimple($this->tenantAdminContext()));
    }
    public function detail()
    {
        $this->assertInstanceToolAccess();
        $params = ['menu_key' => $this->request->get('menu_key')];
        $this->validate($params, MenuValidate::class . '.detail');
        return $this->data($this->menus->detail($params['menu_key']));
    }

    public function add()
    {
        $this->assertInstanceToolAccess();
        $params = $this->validateInput(
            $this->request->post(),
            MenuValidate::class . '.add',
            ['name', 'type', 'parent_key', 'icon', 'sort', 'perms', 'paths', 'component', 'is_cache', 'is_show', 'is_disable'],
        )->writable();
        $this->menus->add($params);
        return $this->success('操作成功');
    }

    public function edit()
    {
        $this->assertInstanceToolAccess();
        $params = $this->validateInput(
            $this->request->post(),
            MenuValidate::class . '.edit',
            ['menu_key', 'name', 'type', 'parent_key', 'icon', 'sort', 'perms', 'paths', 'component', 'is_cache', 'is_show', 'is_disable'],
        )->writable();
        $this->menus->edit($params);
        return $this->success('操作成功');
    }

    public function delete()
    {
        $this->assertInstanceToolAccess();
        $params = ['menu_key' => $this->request->post('menu_key')];
        $this->validate($params, MenuValidate::class . '.delete');
        $this->menus->delete($params['menu_key']);
        return $this->success('操作成功');
    }

    public function updateStatus()
    {
        $this->assertInstanceToolAccess();
        $params = [
            'menu_key' => $this->request->post('menu_key'),
            'is_disable' => $this->request->post('is_disable'),
        ];
        $this->validate($params, MenuValidate::class . '.status');
        $this->menus->updateStatus($params['menu_key'], (int) $params['is_disable']);
        return $this->success('操作成功');
    }

    private function assertInstanceToolAccess(): void
    {
        $guard = InstanceToolAccessGuard::fromConfiguredValue(config('deployment.mode'));
        if (!$guard->allows()) {
            throw \app\common\http\ApiProblem::fromEnvelope('实例级菜单管理仅在 standalone 部署中可用', null, 40300);
        }
    }
}
