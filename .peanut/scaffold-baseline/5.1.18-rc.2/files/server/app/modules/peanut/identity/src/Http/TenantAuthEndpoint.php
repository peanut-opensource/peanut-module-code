<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Http;

use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthentication;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Identity\Auth\TenantSelectionRequired;
use SensitiveParameter;
use app\common\http\SessionRefreshCookie;

final readonly class TenantAuthEndpoint
{
    public function __construct(private TenantAuthService $auth) {}

    public function login(
        string $email,
        #[SensitiveParameter]
        string $password,
        ?string $tenantCode,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
        array $observedCookieNames = [],
    ): TenantAuthResponse {
        return $this->outcome($this->auth->login(
            $email,
            $password,
            $tenantCode,
            $ipAddress,
            $userAgent,
            $requestId,
        ), $requestId, $observedCookieNames);
    }

    public function selectTenant(
        #[SensitiveParameter]
        string $challengeToken,
        int $tenantId,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
        array $observedCookieNames = [],
    ): TenantAuthResponse {
        return $this->authenticated($this->auth->selectTenant(
            $challengeToken,
            $tenantId,
            $ipAddress,
            $userAgent,
            $requestId,
        ), $requestId, $observedCookieNames);
    }

    public function refresh(
        #[SensitiveParameter]
        string $refreshToken,
        #[SensitiveParameter]
        string $expectedAccessToken,
        bool $trustedOrigin,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
        int $expectedTenantId,
    ): TenantAuthResponse {
        if (!$trustedOrigin) {
            throw new AuthException('AUTH_TOKEN_INVALID', 401);
        }

        return $this->authenticated($this->auth->refresh(
            $refreshToken,
            $expectedAccessToken,
            $ipAddress,
            $userAgent,
            $requestId,
            $expectedTenantId,
        ), $requestId, [$this->refreshCookieName($expectedAccessToken)]);
    }

    public function context(#[SensitiveParameter] string $accessToken, string $requestId): TenantAuthResponse
    {
        $context = $this->auth->context($accessToken, $requestId);

        return new TenantAuthResponse(200, [
            'data' => [
                'audience' => 'tenant',
                'account_id' => (string) $context->accountId,
                'tenant_id' => (string) $context->tenantId,
                'tenant_member_id' => (string) $context->memberId,
                'authorization_revision' => (string) $context->authorizationRevision,
            ],
            'meta' => ['request_id' => $requestId],
        ]);
    }

    public function switchChallenge(
        #[SensitiveParameter]
        string $accessToken,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): TenantAuthResponse {
        $selection = $this->auth->switchChallenge(
            $accessToken,
            $ipAddress,
            $userAgent,
            $requestId,
        );

        return new TenantAuthResponse(200, [
            'data' => $selection->responseData(),
            'meta' => ['request_id' => $requestId],
        ]);
    }

    public function logout(#[SensitiveParameter] string $accessToken, string $requestId): TenantAuthResponse
    {
        $this->auth->logout($accessToken, $requestId);

        return new TenantAuthResponse(204, null, cookies: SessionRefreshCookie::clear([$this->refreshCookieName($accessToken)]));
    }

    public function logoutAll(#[SensitiveParameter] string $accessToken, string $requestId): TenantAuthResponse
    {
        $this->auth->logoutAll($accessToken, $requestId);

        return new TenantAuthResponse(204, null, cookies: SessionRefreshCookie::clear([$this->refreshCookieName($accessToken)]));
    }

    private function outcome(
        TenantSelectionRequired|TenantAuthentication $outcome,
        string $requestId,
        array $observedCookieNames,
    ): TenantAuthResponse {
        if ($outcome instanceof TenantAuthentication) {
            return $this->authenticated($outcome, $requestId, $observedCookieNames);
        }

        return new TenantAuthResponse(200, [
            'data' => $outcome->responseData(),
            'meta' => ['request_id' => $requestId],
        ], cookies: SessionRefreshCookie::clear($observedCookieNames));
    }

    private function authenticated(
        TenantAuthentication $authentication,
        string $requestId,
        array $expiredCookieNames,
    ): TenantAuthResponse {
        return new TenantAuthResponse(200, [
            'data' => $authentication->responseData(),
            'meta' => ['request_id' => $requestId],
        ], cookies: [
            ...SessionRefreshCookie::clear($expiredCookieNames),
            $this->refreshCookieName($authentication->tokens->access->expose()) => $authentication->tokens->refresh->expose(),
        ]);
    }

    public function refreshCookieName(#[SensitiveParameter] string $accessToken): string
    {
        return SessionRefreshCookie::name($this->auth->client()->refreshCookieName, $accessToken);
    }

    /** @return list<string> */
    public function observedRefreshCookieNames(object $request): array
    {
        return SessionRefreshCookie::observedNames($request, $this->auth->client()->refreshCookieName);
    }
}
