<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Member\Contract\MemberAdministration;
use PeanutAdmin\Modules\Member\Validation\MemberTagValidate;

/** @property-read MemberAdministration $members 当前 App 中声明式解析的控制器依赖。 */
class MemberTagController extends BaseAdminController
{
    protected string $membersClass = MemberAdministration::class;

    public function lists()
    {
        return $this->data($this->members->tags());
    }

    public function add()
    {
        $params = $this->request->post();
        $this->validate($params, MemberTagValidate::class . '.add');
        $this->members->createTag($params);
        return $this->success('操作成功');
    }

    public function edit()
    {
        $params = $this->request->post();
        $this->validate($params, MemberTagValidate::class . '.edit');
        $this->members->updateTag($params);
        return $this->success('操作成功');
    }

    public function delete()
    {
        $this->members->deleteTag((int) $this->request->post('id'));
        return $this->success('操作成功');
    }
}
