<?php

declare(strict_types=1);

namespace app\api\controller;

use app\api\services\OAuthApplicationService;
use app\api\validate\OAuthValidate;
use app\common\http\RequestTrace;
use PeanutAdmin\Modules\OAuth\Service\OAuthBrowserCallbackService;

/**
 * OAuth HTTP 适配：解析输入及映射响应，用例负责可信绑定和模块调用。
 * @property-read OAuthApplicationService $application 当前 App 中声明式解析的控制器依赖。
 */
class OAuthController extends BaseApiController
{
    protected string $applicationClass = OAuthApplicationService::class;

    public function begin()
    {
        $params = $this->request->post();
        $this->validate($params, OAuthValidate::class . '.begin');
        return $this->data($this->application->begin($params, (string) $this->request->domain(), $this->operationId()));
    }

    public function redirectPc()
    {
        return redirect(OAuthBrowserCallbackService::clientRedirectUrl('pc', $this->request->get()));
    }

    public function redirectOfficialAccount()
    {
        return redirect(OAuthBrowserCallbackService::clientRedirectUrl('official-account', $this->request->get()));
    }

    public function callback()
    {
        $params = $this->request->post();
        $this->validate($params, OAuthValidate::class . '.callback');
        return $this->data($this->application->callback($params, $this->request->ip(), $this->operationId()));
    }

    public function miniProgram()
    {
        $params = $this->request->post();
        $this->validate($params, OAuthValidate::class . '.mnp');
        return $this->data($this->application->miniProgram($params, $this->request->ip(), $this->operationId()));
    }

    public function complete()
    {
        $params = $this->request->post();
        $this->validate($params, OAuthValidate::class . '.complete');
        return $this->data($this->application->complete($params, $this->request->ip(), $this->operationId()));
    }

    public function bind()
    {
        $params = $this->request->post();
        $this->validate($params, OAuthValidate::class . '.bind');
        $this->application->bind($this->memberContext(), (string) $params['scene'], (string) $params['code']);
        return $this->success('绑定成功');
    }

    private function operationId(): string
    {
        return RequestTrace::id($this->executionContext(), $this->request, 'oauth');
    }
}
