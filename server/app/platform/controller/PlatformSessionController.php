<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\platform\http\PlatformRequest;
use app\platform\services\PlatformOperatorSessionService;
use app\platform\validate\PlatformLoginValidate;
use PeanutAdmin\Kernel\Auth\PlatformRefreshCookie;
use app\common\http\SessionRefreshCookie;

/** @property-read PlatformOperatorSessionService $sessions 当前 App 中声明式解析的控制器依赖。 */
final class PlatformSessionController extends BasePlatformController
{
    protected string $sessionsClass = PlatformOperatorSessionService::class;

    public function login()
    {
        $params = $this->request->post();
        $this->validate($params, PlatformLoginValidate::class);
        $oldCookieNames = SessionRefreshCookie::observedNames($this->request, PlatformRefreshCookie::NAME);
        $authentication = $this->sessions->login(
            trim((string) $params['email']),
            (string) $params['password'],
            $this->request->ip(),
            $this->request->header('User-Agent'),
            $this->requestId(),
        );

        return SessionRefreshCookie::apply($this->data($authentication->responseData()), [
            ...SessionRefreshCookie::clear($oldCookieNames),
            SessionRefreshCookie::name(PlatformRefreshCookie::NAME, $authentication->tokens->access->expose()) => $authentication->tokens->refresh->expose(),
        ]);
    }

    public function refresh()
    {
        $token = PlatformRequest::refreshToken($this->request);
        $accessToken = PlatformRequest::bearerToken($this->request);
        $authentication = $this->sessions->refresh(
            $token,
            $this->request->ip(),
            $this->request->header('User-Agent'),
            $this->requestId(),
        );

        return SessionRefreshCookie::apply($this->data($authentication->responseData()), [
            SessionRefreshCookie::name(PlatformRefreshCookie::NAME, $accessToken) => null,
            SessionRefreshCookie::name(PlatformRefreshCookie::NAME, $authentication->tokens->access->expose()) => $authentication->tokens->refresh->expose(),
        ]);
    }

    public function logout()
    {
        $token = PlatformRequest::bearerToken($this->request);
        if ($token !== '') {
            $this->sessions->logout($token);
        }

        return SessionRefreshCookie::apply($this->success('success'), $token === '' ? [] : [
            SessionRefreshCookie::name(PlatformRefreshCookie::NAME, $token) => null,
        ]);
    }

    public function info()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        $permissions = $this->sessions->permissionKeys($this->platformContext);

        return $this->data([
            'audience' => 'platform',
            'account_id' => (string) $this->platformContext->core->accountId,
            'platform_operator_id' => (string) $this->platformContext->core->operatorId,
            'permissions' => $permissions,
            'navigation' => array_values(array_filter([
                in_array('platform.tenant.read', $permissions, true) ? '/platform/tenants' : null,
                in_array('platform.ops.read', $permissions, true) ? '/platform/ops' : null,
                in_array('platform.operator.read', $permissions, true) ? '/platform/operators' : null,
                in_array('platform.role.read', $permissions, true) ? '/platform/roles' : null,
            ])),
        ]);
    }
}
