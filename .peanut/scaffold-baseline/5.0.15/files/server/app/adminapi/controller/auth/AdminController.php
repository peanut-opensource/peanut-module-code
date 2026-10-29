<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\auth\AdminApplicationService;
use app\adminapi\validate\auth\AdminValidate;
use app\adminapi\validate\auth\EditSelfValidate;

/** @property-read AdminApplicationService $admins 当前 App 中声明式解析的控制器依赖。 */
class AdminController extends BaseAdminController
{
    protected string $adminsClass = AdminApplicationService::class;

    public function lists()
    {
        $params = $this->admins->normalizeInput($this->request->get());
        $this->validate($params, AdminValidate::class . '.lists');
        return $this->data($this->admins->lists($this->tenantAdminContext(), $params));
    }

    public function detail()
    {
        $params = ['id' => (int) $this->request->get('id')];
        $this->validate($params, ['id' => 'require|integer|gt:0']);
        return $this->data($this->admins->detail($this->tenantAdminContext(), $params['id']));
    }

    public function self()
    {
        return $this->data($this->admins->detail($this->tenantAdminContext(), $this->adminId));
    }
    public function editSelf()
    {
        $params = $this->request->post();
        $this->validate($params, EditSelfValidate::class);
        $this->admins->editSelf(
            $this->tenantAdminContext(),
            $this->adminId,
            $params,
            $this->request->ip(),
            $this->request->header('User-Agent', ''),
        );
        return $this->success('操作成功');
    }

    public function add()
    {
        $params = $this->admins->normalizeInput($this->request->post());
        $this->validate($params, $this->admins->validationRules('add'));
        $this->admins->add($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }

    public function edit()
    {
        $params = $this->admins->normalizeInput($this->request->post());
        $this->validate($params, $this->admins->validationRules('edit'));
        $this->admins->edit($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }

    public function delete()
    {
        $params = ['id' => (int) $this->request->post('id')];
        $this->validate($params, ['id' => 'require|integer|gt:0']);
        $this->admins->delete($this->tenantAdminContext(), $params['id'], $this->adminId);
        return $this->success('操作成功');
    }

    public function updateStatus()
    {
        $params = [
            'id' => (int) $this->request->post('id'),
            'disable' => $this->request->post('disable'),
        ];
        $this->validate($params, ['id' => 'require|integer|gt:0', 'disable' => 'require|in:0,1']);
        $this->admins->updateStatus($this->tenantAdminContext(), $params['id'], (int) $params['disable'], $this->adminId);
        return $this->success('操作成功');
    }
}
