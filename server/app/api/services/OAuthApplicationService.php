<?php

declare(strict_types=1);

namespace app\api\services;

use app\common\exception\BusinessException;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCallbackLocator;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCommands;
use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthLoginResult;
use PeanutAdmin\Modules\OAuth\Service\OAuthBrowserCallbackService;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;

/**
 * 用户端 OAuth 用例入口：解析可信外部绑定、建立执行范围、调用模块并组织登录结果。
 * HTTP 参数格式与响应协议留给控制器；不接收客户端提供的可信租户或操作人。
 * 上下文由现有栈在成功和异常路径统一恢复，不在对象构造阶段执行认证或业务。
 */
final readonly class OAuthApplicationService
{
    public function __construct(
        private OAuthCommands $commands,
        private OAuthCallbackLocator $callbackLocator,
        private ExecutionContextStore $executionContexts,
        private ModuleExecutionBoundary $modules,
        private ExternalTenantResolutionService $externalTenants,
        private UserTokenService $tokens,
        private FileReferences $files,
    ) {}

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function begin(array $params, string $domain, string $operationId): array
    {
        $scene = (string) $params['scene'];
        if (!in_array($scene, ['oa', 'open_pc'], true)) {
            throw BusinessException::invalid('OAUTH_SCENE_UNSUPPORTED', '该微信场景不支持浏览器授权');
        }
        $callbackUrl = OAuthBrowserCallbackService::callbackUrl($domain, $scene);
        $provider = ExternalProvider::oauth($scene);
        $clientId = trim((string) ($params['client_id'] ?? ''));
        $resolution = $clientId === ''
            ? $this->externalTenants->onlyActiveBinding($provider, 'oauth.begin', $operationId)
            : $this->externalTenants->clientIdentity($provider, $clientId, 'oauth.begin', $operationId);
        $result = $this->executionContexts->run(
            new SystemExecutionContext($resolution->context),
            function () use ($resolution, $scene, $params, $callbackUrl) {
                $this->modules->assertExternalCallback('peanut.oauth');
                return $this->commands->begin(
                    $resolution->context,
                    $scene,
                    (string) $params['return_path'],
                    $callbackUrl,
                    $resolution->binding,
                );
            },
        );
        return $result->toArray();
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function callback(array $params, string $ip, string $operationId): array
    {
        $provider = ExternalProvider::oauth((string) $params['scene']);
        $state = (string) $params['state'];
        $resolution = $this->externalTenants->verifiedCandidates(
            $this->callbackLocator->locateState($provider, hash('sha256', trim($state))),
            $provider,
            $state,
            'oauth.callback',
            $operationId,
        );
        $result = $this->executionContexts->run(
            new SystemExecutionContext($resolution->context),
            function () use ($resolution, $params, $ip) {
                $this->modules->assertExternalCallback('peanut.oauth');
                return $this->commands->callback(
                    $resolution->context,
                    (string) $params['scene'],
                    (string) $params['code'],
                    (string) $params['state'],
                    $resolution->binding,
                    $ip,
                );
            },
        );
        return $this->loginResult($result);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function miniProgram(array $params, string $ip, string $operationId): array
    {
        $clientId = trim((string) ($params['client_id'] ?? ''));
        $resolution = $clientId === ''
            ? $this->externalTenants->onlyActiveBinding(ExternalProvider::WECHAT_MINI_PROGRAM, 'oauth.mini-program', $operationId)
            : $this->externalTenants->clientIdentity(ExternalProvider::WECHAT_MINI_PROGRAM, $clientId, 'oauth.mini-program', $operationId);
        $result = $this->executionContexts->run(
            new SystemExecutionContext($resolution->context),
            function () use ($resolution, $params, $ip) {
                $this->modules->assertExternalCallback('peanut.oauth');
                return $this->commands->miniProgramLogin($resolution->context, (string) $params['code'], $resolution->binding, $ip);
            },
        );
        return $this->loginResult($result);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function complete(array $params, string $ip, string $operationId): array
    {
        $params['code'] = (string) ($params['verification_code'] ?? '');
        $ticket = (string) $params['ticket'];
        $resolution = $this->externalTenants->verifiedCandidates(
            $this->callbackLocator->locateTicket(hash('sha256', trim($ticket))),
            'oauth.wechat.completion',
            $ticket,
            'oauth.complete',
            $operationId,
            false,
        );
        $result = $this->executionContexts->run(
            new SystemExecutionContext($resolution->context),
            function () use ($resolution, $params, $ip) {
                $this->modules->assertExternalCallback('peanut.oauth');
                return $this->commands->complete($resolution->context, $params, $ip);
            },
        );
        return $this->loginResult($result);
    }

    public function bind(AuthenticatedMemberContext $member, string $scene, string $code): void
    {
        $this->commands->bind($member, $member->memberId, $scene, $code);
    }

    /** 仅完整登录签发凭证；待补资料状态继续返回原票据协议。 @return array<string,mixed> */
    private function loginResult(OAuthLoginResult $result): array
    {
        $data = [
            'completed' => $result->completed,
            'member' => [
                'id' => $result->member->id,
                'sn' => $result->member->sn,
                'nickname' => $result->member->nickname,
                'avatar' => $this->files->getFileUrl($result->member->avatar),
                'mobile' => $result->member->mobile,
            ],
        ];
        if ($result->completed) {
            $data['token'] = $this->tokens->createToken($result->member->id);
        } else {
            $data += [
                'completion_ticket' => $result->completionTicket,
                'expires_in' => $result->expiresIn,
                'need_profile' => $result->needProfile,
                'need_mobile' => $result->needMobile,
            ];
        }
        if ($result->returnPath !== null) {
            $data['return_path'] = $result->returnPath;
        }
        return $data;
    }
}
