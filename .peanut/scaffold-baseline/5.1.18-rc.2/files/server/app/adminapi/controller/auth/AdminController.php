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
        $params = ['id' => $this->memberId($this->request->get('id'))];
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
        $params = ['id' => $this->memberId($this->request->post('id'))];
        $this->admins->delete($this->tenantAdminContext(), $params['id'], $this->adminId);
        return $this->success('操作成功');
    }

    public function updateStatus()
    {
        $params = [
            'id' => $this->memberId($this->request->post('id')),
            'disable' => $this->request->post('disable'),
        ];
        $this->validate($params, ['disable' => [
            'require', static fn(mixed $value): bool => in_array($value, [0,1,'0','1'], true),
        ]]);
        $this->admins->updateStatus($this->tenantAdminContext(), $params['id'], (int) $params['disable'], $this->adminId);
        return $this->success('操作成功');
    }

    private function memberId(mixed $value): int
    {
        $this->validate(['id' => $value], ['id' => [
            'require', static fn(mixed $id): bool => (is_int($id) || is_string($id))
                && preg_match('/^[1-9][0-9]*$/D', (string) $id) === 1
                && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false,
        ]]);
        return (int) $value;
    }
}
