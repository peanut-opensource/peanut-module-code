<?php

declare(strict_types=1);

namespace app\api\controller;

use app\api\services\UserApplicationService;
use app\common\exception\BusinessException;

/** @property-read UserApplicationService $users 当前 App 中声明式解析的控制器依赖。 */
class UserController extends BaseApiController
{
    protected string $usersClass = UserApplicationService::class;

    /** 用户中心 */
    public function center()
    {
        $data = $this->users->center($this->memberContext(), $this->memberId);
        return $this->data($data);
    }

    /** 个人信息 */
    public function info()
    {
        $data = $this->users->info($this->memberContext(), $this->memberId);
        return $this->data($data);
    }

    /** 编辑用户信息 */
    public function setInfo()
    {
        $params = [
            'field' => $this->request->post('field/s', ''),
            'value' => $this->request->post('value', ''),
        ];

        $this->users->setInfo($this->memberContext(), $this->memberId, $params);
        return $this->success('修改成功');
    }

    /** 修改密码 */
    public function changePassword()
    {
        $params = [
            'old_password' => $this->request->post('old_password/s', ''),
            'password'     => $this->request->post('password/s', ''),
        ];

        if (empty($params['old_password']) || empty($params['password'])) {
            throw BusinessException::invalid('MEMBER_PASSWORD_CHANGE_INVALID', '旧密码和新密码不能为空');
        }

        $this->users->changePassword($this->memberContext(), $this->memberId, $params);
        return $this->success('修改成功');
    }

    /** 绑定/变更手机号 */
    public function bindMobile()
    {
        $params = [
            'mobile' => $this->request->post('mobile/s', ''),
            'code'   => $this->request->post('code/s', ''),
        ];

        $this->users->bindMobile($this->memberContext(), $this->memberId, $params);
        return $this->success('绑定成功');
    }
}
