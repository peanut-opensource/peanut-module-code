<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Member\Contract\MemberAdministration;
use PeanutAdmin\Modules\Member\Validation\MemberValidate;

/** @property-read MemberAdministration $members 当前 App 中声明式解析的控制器依赖。 */
class MemberController extends BaseAdminController
{
    protected string $membersClass = MemberAdministration::class;

    public function lists()
    {
        return $this->data($this->members->members($this->request->get()));
    }

    public function detail()
    {
        $this->validate($this->request->get(), MemberValidate::class . '.detail');
        return $this->data($this->members->memberDetail((int) $this->request->get('id')));
    }

    public function add()
    {
        $params = $this->request->post();
        $this->validate($params, MemberValidate::class . '.add');
        $this->members->createMember($params);
        return $this->success('操作成功');
    }

    public function edit()
    {
        $params = $this->request->post();
        $this->validate($params, MemberValidate::class . '.setInfo');
        $this->members->updateMemberField($params);
        return $this->success('操作成功');
    }

    public function updateStatus()
    {
        $params = $this->request->post();
        $this->validate($params, MemberValidate::class . '.status');
        $this->members->updateMemberStatus((int) $params['id'], (int) $params['status']);
        return $this->success('操作成功');
    }

    public function adjustMoney()
    {
        $params = $this->request->post();
        $this->validate($params, MemberValidate::class . '.adjustMoney');
        $this->members->adjustMemberBalance($params, $this->adminId, $this->idempotencyKey());
        return $this->success('操作成功');
    }

    private function idempotencyKey(): string
    {
        $key = trim((string) $this->request->header('Idempotency-Key', ''));
        return $key;
    }

}
