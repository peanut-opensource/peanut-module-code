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
        $apiAccess = app(\PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog::class)->platformAccess(
            $permissions,
            app(\PeanutAdmin\Kernel\Module\ModuleAvailability::class),
            app(\PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy::class)->platformMutationLocked($this->platformContext->core->accountId),
        );
        $readable = array_column(array_filter($apiAccess, static fn(array $api): bool => $api['method'] === 'GET'), 'path');
        $navigation = [
            '/platform/#/tenants' => ['/platformapi/tenants'],
            '/platform/#/operators' => ['/platformapi/operators'],
            '/platform/#/roles' => ['/platformapi/roles'],
            '/platform/#/permission-bundles' => ['/platformapi/permission/bundles'],
            '/platform/#/permission-apis' => ['/platformapi/permission/apis'],
            '/platform/#/permission-menus' => ['/platformapi/permission/menus'],
            '/platform/#/storage-accounts' => ['/platformapi/infrastructure/storage/accounts', '/platformapi/infrastructure/storage'],
            '/platform/#/storage-spaces' => ['/platformapi/infrastructure/storage/spaces', '/platformapi/infrastructure/storage'],
            '/platform/#/storage-routes' => ['/platformapi/infrastructure/storage/routes', '/platformapi/infrastructure/storage'],
            '/platform/#/ops' => ['/platformapi/v1/ops/status'],
            '/platform/#/maintenance' => ['/platformapi/v1/ops/maintenance'],
            '/platform/#/backups' => ['/platformapi/v1/ops/backups'],
            '/platform/#/upgrades' => ['/platformapi/v1/ops/upgrade-readiness', '/platformapi/v1/ops/upgrades'],
            '/platform/#/diagnostics' => ['/platformapi/v1/ops/logs', '/platformapi/v1/ops/diagnostics'],
            '/platform/#/providers' => ['/platformapi/v1/ops/providers'],
            '/platform/#/audit' => ['/platformapi/audit'],
        ];
        if (in_array('/platformapi/roles', $readable, true) && in_array('/platformapi/permissions', $readable, true)) {
            $navigation['/platform/#/role-permissions'] = ['/platformapi/roles'];
        }

        return $this->data([
            'audience' => 'platform',
            'account_id' => (string) $this->platformContext->core->accountId,
            'platform_operator_id' => (string) $this->platformContext->core->operatorId,
            'permissions' => $permissions,
            'api_access' => $apiAccess,
            'navigation' => array_keys(array_filter($navigation, static fn(array $apis): bool => array_intersect($apis, $readable) !== [])),
        ]);
    }
}
