<?php

declare(strict_types=1);

namespace app\api\controller;

use think\App;
use app\api\services\LoginApplicationService;
use app\api\services\VerificationAttemptRateLimiter;
use app\common\exception\BusinessException;

class LoginController extends BaseApiController
{
    public function __construct(
        App $app,
        private readonly LoginApplicationService $login,
        private readonly VerificationAttemptRateLimiter $verificationAttempts,
    ) {
        parent::__construct($app);
    }


    /** 注册账号 */
    public function register()
    {
        $params = [
            'account'  => $this->request->post('account/s', ''),
            'password' => $this->request->post('password/s', ''),
        ];

        if (empty($params['account']) || empty($params['password'])) {
            throw BusinessException::invalid('MEMBER_CREDENTIALS_REQUIRED', '账号和密码不能为空');
        }

        $this->login->register($this->publicTenantContext('member.register'), $params);
        return $this->success('注册成功');
    }

    /** 账号/手机号 + 密码登录 */
    public function account()
    {
        $params = [
            'account'  => $this->request->post('account/s', ''),
            'password' => $this->request->post('password/s', ''),
            'terminal' => $this->request->post('terminal/d', 1),
        ];

        if (empty($params['account']) || empty($params['password'])) {
            throw BusinessException::invalid('MEMBER_CREDENTIALS_REQUIRED', '账号和密码不能为空');
        }

        return $this->data($this->login->login(
            $this->publicTenantContext('member.login'),
            $params,
            $this->request->ip(),
        ));
    }

    /** 手机号验证码登录 */
    public function mobile()
    {
        $params = [
            'mobile' => $this->request->post('mobile/s', ''),
            'code'   => $this->request->post('code/s', ''),
        ];
        if (!preg_match('/^1[3-9]\d{9}$/', $params['mobile']) || $params['code'] === '') {
            throw BusinessException::invalid('MEMBER_MOBILE_LOGIN_INVALID', '手机号或验证码格式不正确');
        }

        $context = $this->publicTenantContext('notice.verification.verify');
        $source = $this->request->ip();
        $this->verificationAttempts->assertAllowed($context, 'login_code', $params['mobile'], $source);
        try {
            return $this->data($this->login->mobileLogin($context, $params, $source));
        } catch (BusinessException $exception) {
            if ($exception->errorCode === 'MEMBER_VERIFICATION_REJECTED') {
                $this->verificationAttempts->recordFailure($context, 'login_code', $params['mobile'], $source);
            }
            throw $exception;
        }
    }

    /** 手机号验证码找回密码 */
    public function resetPassword()
    {
        $params = [
            'mobile'   => $this->request->post('mobile/s', ''),
            'code'     => $this->request->post('code/s', ''),
            'password' => $this->request->post('password/s', ''),
        ];
        if (!preg_match('/^1[3-9]\d{9}$/', $params['mobile'])
            || $params['code'] === '' || $params['password'] === '') {
            throw BusinessException::invalid('MEMBER_PASSWORD_RESET_INVALID', '手机号、验证码或新密码格式不正确');
        }

        $context = $this->publicTenantContext('notice.verification.verify');
        $source = $this->request->ip();
        $this->verificationAttempts->assertAllowed($context, 'reset_password', $params['mobile'], $source);
        try {
            $this->login->resetPassword($context, $params);
        } catch (BusinessException $exception) {
            if ($exception->errorCode === 'MEMBER_VERIFICATION_REJECTED') {
                $this->verificationAttempts->recordFailure($context, 'reset_password', $params['mobile'], $source);
            }
            throw $exception;
        }
        return $this->success('密码已重置');
    }

    /** 退出登录 */
    public function logout()
    {
        $authorization = (string) $this->request->header('Authorization', '');
        $token = preg_match(
            '/^Bearer +([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD',
            $authorization,
            $matches,
        ) === 1 ? $matches[1] : '';
        if ($token === '') {
            throw \app\common\http\ApiProblem::fromEnvelope('请求缺少 token', null, 40100);
        }
        try {
            $this->login->logout($token);
        } catch (\UnexpectedValueException) {
            throw \app\common\http\ApiProblem::fromEnvelope('登录超时，请重新登录', null, 40100);
        }
        return $this->success('退出成功');
    }
}
