<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\auth\MenuApplicationService;
use app\adminapi\validate\auth\MenuValidate;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\http\JsonResponseFactory;
use think\response\Json;

/** @property-read MenuApplicationService $menus 当前 App 中声明式解析的控制器依赖。 */
class MenuController extends BaseAdminController
{
    protected string $menusClass = MenuApplicationService::class;

    public function route()
    {
        return $this->data($this->menus->getMenuByAdminId($this->executionContext()->tenantAdmin(), $this->adminId));
    }
    public function lists()
    {
        return $this->instanceMenuDenial() ?? $this->data($this->menus->getAll());
    }
    public function all()
    {
        return $this->data($this->menus->getAllSimple($this->tenantAdminContext()));
    }
    public function detail()
    {
        if ($denial = $this->instanceMenuDenial()) {
            return $denial;
        }
        $params = ['menu_key' => $this->request->get('menu_key')];
        $this->validate($params, MenuValidate::class . '.detail');
        return $this->data($this->menus->detail($params['menu_key']));
    }

    public function add()
    {
        if ($denial = $this->instanceMenuDenial()) {
            return $denial;
        }
        $this->validate($this->request->post(), MenuValidate::class . '.add');
        $this->menus->add($this->request->post());
        return $this->success('操作成功');
    }

    public function edit()
    {
        if ($denial = $this->instanceMenuDenial()) {
            return $denial;
        }
        $this->validate($this->request->post(), MenuValidate::class . '.edit');
        $this->menus->edit($this->request->post());
        return $this->success('操作成功');
    }

    public function delete()
    {
        if ($denial = $this->instanceMenuDenial()) {
            return $denial;
        }
        $params = ['menu_key' => $this->request->post('menu_key')];
        $this->validate($params, MenuValidate::class . '.delete');
        $this->menus->delete($params['menu_key']);
        return $this->success('操作成功');
    }

    public function updateStatus()
    {
        if ($denial = $this->instanceMenuDenial()) {
            return $denial;
        }
        $params = [
            'menu_key' => $this->request->post('menu_key'),
            'is_disable' => $this->request->post('is_disable'),
        ];
        $this->validate($params, MenuValidate::class . '.status');
        $this->menus->updateStatus($params['menu_key'], (int) $params['is_disable']);
        return $this->success('操作成功');
    }

    private function instanceMenuDenial(): ?Json
    {
        $guard = InstanceToolAccessGuard::fromConfiguredValue(config('deployment.mode'));
        return $guard->allows()
            ? null
            : throw \app\common\http\ApiProblem::fromEnvelope('实例级菜单管理仅在 standalone 部署中可用', null, 40300);
    }
}
