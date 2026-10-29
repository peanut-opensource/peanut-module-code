<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Service;

use PeanutAdmin\Modules\Notification\Contract\VerificationCodeCommands;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberIdentitySnapshot;
use PeanutAdmin\Modules\Member\Contract\MemberIdentityCommands;
use PeanutAdmin\Modules\Member\Contract\MemberProfileCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use app\common\enum\notice\NoticeSceneEnum;
use app\common\exception\BusinessException;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use think\facade\Db;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\IntegrationSecurity\OAuth\OAuthProfile;
use PeanutAdmin\IntegrationSecurity\OAuth\OAuthTransport;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCommands;
use PeanutAdmin\Modules\OAuth\Contract\OAuthPersistence;
use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthAuthorizationResult;
use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthLoginResult;

/** 微信 OAuth 登录、身份归一、绑定与受限首登补全。 */
final class OAuthCommandService implements OAuthCommands
{
    private const PROVIDER = 'wechat';
    private const UNION_SCOPE = 'wechat_default';
    private const ATTEMPT_TTL = 600;
    private const COMPLETION_TTL = 600;
    private const SCENES = [
        'mnp' => ['terminal' => 1],
        'oa' => ['terminal' => 2],
        'open_pc' => ['terminal' => 4],
    ];

    public function __construct(
        private readonly MemberQueries $members,
        private readonly MemberIdentityCommands $memberIdentities,
        private readonly MemberProfileCommands $memberProfiles,
        private readonly VerificationCodeCommands $verificationCodes,
        private readonly AdvisoryLockExecution $locks,
        private readonly TenantApplicationSettings $applicationSettings,
        private readonly ExternalTenantResolutionService $externalTenants,
        private readonly OAuthPersistence $persistence,
        private readonly OAuthTransport $transport,
        private readonly string $defaultAvatar,
    ) {}

    public function begin(
        TenantSystemContext $context,
        string $scene,
        string $returnPath,
        string $redirectUri,
        ExternalTenantBinding $binding,
    ): OAuthAuthorizationResult {
        if (!in_array($scene, ['oa', 'open_pc'], true)) {
            throw BusinessException::invalid('OAUTH_SCENE_UNSUPPORTED', '该微信场景不支持浏览器授权');
        }
        self::assertReturnPath($returnPath);
        $config = $binding->config;
        $state = bin2hex(random_bytes(32));
        $this->persistence->createAttempt($context, [
            'state_hash' => hash('sha256', $state),
            'scene' => $scene,
            'return_path' => $returnPath,
            'expires_at' => time() + self::ATTEMPT_TTL,
            'used_at' => null,
        ]);
        return new OAuthAuthorizationResult(
            $this->transport->authorizationUrl(
                $scene,
                $config,
                $redirectUri,
                $state,
            ),
            self::ATTEMPT_TTL,
        );
    }

    public function callback(
        TenantSystemContext $context,
        string $scene,
        string $code,
        string $state,
        ExternalTenantBinding $binding,
        string $ip,
    ): OAuthLoginResult {
        if (!in_array($scene, ['oa', 'open_pc'], true)) {
            throw BusinessException::invalid('OAUTH_SCENE_INVALID', '微信授权场景无效');
        }
        $returnPath = $this->consumeAttempt($context, $scene, $state);
        $profile = $this->transport->exchange($scene, $binding->config, $code);
        $result = $this->loginWithProfile($context, $scene, $profile, $binding, $ip);
        return $result->withReturnPath($returnPath);
    }

    public function miniProgramLogin(
        TenantSystemContext $context,
        string $code,
        ExternalTenantBinding $binding,
        string $ip,
    ): OAuthLoginResult {
        $profile = $this->transport->exchange('mnp', $binding->config, $code);
        return $this->loginWithProfile($context, 'mnp', $profile, $binding, $ip);
    }

    public function bind(
        AuthenticatedMemberContext $context,
        int $memberId,
        string $scene,
        string $code,
    ): bool {
        if (!in_array($scene, ['mnp', 'oa'], true)) {
            throw BusinessException::invalid('OAUTH_BIND_SCENE_UNSUPPORTED', '该微信场景不支持账号绑定');
        }
        $member = $this->members->identity($context, $memberId);
        if ($member === null || !$member->status) {
            throw BusinessException::forbidden('MEMBER_UNAVAILABLE', '用户不存在或已禁用');
        }
        $binding = $this->externalTenants->bindingForTenant(
            ExternalTenantContext::tenantId($context),
            ExternalProvider::oauth($scene),
        );
        $profile = $this->transport->exchange($scene, $binding->config, $code);
        [$bound] = $this->resolveIdentity($context, $scene, $profile, $memberId, $binding);
        if ((int) $bound->id !== $memberId) {
            throw BusinessException::conflict('OAUTH_IDENTITY_ALREADY_BOUND', '微信身份已绑定其他用户');
        }
        return true;
    }

    public function complete(
        TenantContext|TenantSystemContext $context,
        array $params,
        string $ip,
    ): OAuthLoginResult {
        $rawTicket = trim((string) ($params['ticket'] ?? ''));
        if ($rawTicket === '') {
            throw BusinessException::invalid('OAUTH_COMPLETION_TICKET_REQUIRED', '登录补全票据缺失');
        }

        return Db::transaction(function () use ($context, $params, $rawTicket, $ip): OAuthLoginResult {
            $ticket = $this->persistence->completionForUpdate($context, hash('sha256', $rawTicket));
            if ($ticket === null || $ticket->usedAt !== null || $ticket->expiresAt < time()) {
                throw BusinessException::invalid('OAUTH_COMPLETION_TICKET_INVALID', '登录补全票据无效或已过期');
            }
            $member = $this->members->lockedIdentity(
                $context,
                $ticket->memberId,
            );
            if ($member === null || !$member->status) {
                throw BusinessException::forbidden('MEMBER_UNAVAILABLE', '用户不存在或已禁用');
            }

            $nickname = null;
            $avatar = null;
            if ($ticket->needProfile) {
                $nickname = trim((string) ($params['nickname'] ?? ''));
                if ($nickname === '' || mb_strlen($nickname) > 50) {
                    throw BusinessException::invalid('MEMBER_NICKNAME_INVALID', '请填写有效昵称');
                }
                if (trim((string) ($params['avatar'] ?? '')) !== '') {
                    // Storage URL ownership remains outside OAuth; Member persists the opaque value.
                    $avatar = (string) $params['avatar'];
                }
            }

            if ($ticket->needMobile) {
                $mobile = trim((string) ($params['mobile'] ?? ''));
                if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
                    throw BusinessException::invalid('MEMBER_MOBILE_INVALID', '手机号格式错误');
                }
                $this->memberIdentities->assertMobileAvailable(
                    $context,
                    $member->id,
                    $mobile,
                );
                $result = $this->verificationCodes->verifyCode(
                    $context,
                    NoticeSceneEnum::BIND_MOBILE,
                    $mobile,
                    (string) ($params['code'] ?? ''),
                );
                if (!$result->accepted) {
                    throw BusinessException::invalid('MEMBER_VERIFICATION_REJECTED', $result->error);
                }
                $this->memberIdentities->bindVerifiedMobile(
                    $context,
                    $member->id,
                    $mobile,
                );
            }

            $this->memberProfiles->completeOAuthProfile(
                $context,
                $member->id,
                $nickname,
                $avatar,
                time(),
                $ip,
            );
            $member = $this->members->identity($context, $member->id);
            if ($member === null) {
                throw BusinessException::notFound('MEMBER_NOT_FOUND', '用户不存在');
            }
            $this->persistence->markCompletionUsed($context, $ticket->id, time());
            return $this->fullLoginResult($member);
        });
    }

    private function loginWithProfile(
        TenantSystemContext $context,
        string $scene,
        OAuthProfile $profile,
        ExternalTenantBinding $binding,
        string $ip,
    ): OAuthLoginResult {
        [$member, $created] = $this->resolveIdentity($context, $scene, $profile, null, $binding);
        if (!$member->status) {
            throw BusinessException::forbidden('MEMBER_DISABLED', '账号已被禁用');
        }
        $needProfile = $created && $scene === 'mnp';
        $needMobile = (int) $this->applicationSettings->login($context)['coerce_mobile'] === 1
            && trim($member->mobile) === '';
        if ($needProfile || $needMobile) {
            return $this->completionResult($context, $member, $needProfile, $needMobile, $binding);
        }
        $this->memberIdentities->recordLogin($context, $member->id, $ip);
        return $this->fullLoginResult($member);
    }

    /** @return array{0:MemberIdentitySnapshot,1:bool} */
    private function resolveIdentity(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $scene,
        OAuthProfile $profile,
        ?int $bindingMemberId,
        ExternalTenantBinding $binding,
    ): array {
        $sceneMeta = self::SCENES[$scene];
        $clientKey = $scene . ':' . (string) ($binding->config['app_id'] ?? '');
        $tenantId = $context->tenantId;
        $lockSeed = $profile->unionId() !== ''
            ? 'union:' . $profile->unionId()
            : 'identity:' . $clientKey . ':' . $profile->subject();
        $lockName = 'peanut:oauth:' . substr(hash('sha256', $tenantId . ':' . $lockSeed), 0, 48);
        try {
            return $this->locks->run(
                $lockName,
                5,
                fn(): array => Db::transaction(function () use (
                    $bindingMemberId,
                    $clientKey,
                    $context,
                    $profile,
                    $sceneMeta,
                ): array {
                    $identity = $this->persistence->identityBySubjectForUpdate(
                        $context,
                        self::PROVIDER,
                        $clientKey,
                        $profile->subject(),
                    );
                    if ($identity !== null) {
                        if ($bindingMemberId !== null && $identity->memberId !== $bindingMemberId) {
                            throw BusinessException::conflict('OAUTH_IDENTITY_ALREADY_BOUND', '微信身份已绑定其他用户');
                        }
                        $member = $this->members->lockedIdentity(
                            $context,
                            $identity->memberId,
                        );
                        if ($member === null) {
                            throw BusinessException::notFound('OAUTH_MEMBER_NOT_FOUND', '微信身份关联用户不存在');
                        }
                        $principalId = $this->assertPrincipalOwnership($context, $profile, $member->id);
                        if ($principalId !== null && $identity->principalId !== $principalId) {
                            $this->persistence->updateIdentityPrincipal($context, $identity->id, $principalId);
                        }
                        $member = $this->updateProfile($context, $member, $profile);
                        return [$member, false];
                    }

                    $principal = null;
                    if ($profile->unionId() !== '') {
                        $principal = $this->persistence->principalByUnionForUpdate(
                            $context,
                            self::PROVIDER,
                            self::UNION_SCOPE,
                            $profile->unionId(),
                        );
                    }

                    $created = false;
                    if ($bindingMemberId !== null) {
                        $member = $this->members->lockedIdentity($context, $bindingMemberId);
                        if ($member === null) {
                            throw BusinessException::notFound('MEMBER_NOT_FOUND', '用户不存在');
                        }
                        if ($principal !== null && $principal->memberId !== $bindingMemberId) {
                            throw BusinessException::conflict('OAUTH_PRINCIPAL_OWNERSHIP_CONFLICT', '微信联合身份已归属其他用户，不能自动合并账号');
                        }
                    } elseif ($principal !== null) {
                        $member = $this->members->lockedIdentity(
                            $context,
                            $principal->memberId,
                        );
                        if ($member === null) {
                            throw BusinessException::notFound('OAUTH_MEMBER_NOT_FOUND', '微信联合身份关联用户不存在');
                        }
                    } else {
                        if ($context instanceof AuthenticatedMemberContext) {
                            throw BusinessException::forbidden('OAUTH_MEMBER_CREATION_FORBIDDEN', '已认证会员不能创建替代会员身份');
                        }
                        $member = $this->createMember($context, $profile, (int) $sceneMeta['terminal']);
                        $created = true;
                    }

                    if ($profile->unionId() !== '' && $principal === null) {
                        $principal = $this->persistence->createPrincipal($context, [
                            'provider' => self::PROVIDER,
                            'union_scope' => self::UNION_SCOPE,
                            'union_id' => $profile->unionId(),
                            'member_id' => $member->id,
                        ]);
                    }
                    $sameClient = $this->persistence->identityByMemberForUpdate(
                        $context,
                        self::PROVIDER,
                        $clientKey,
                        $member->id,
                    );
                    if ($sameClient !== null) {
                        throw BusinessException::conflict('OAUTH_CLIENT_IDENTITY_EXISTS', '当前用户已绑定该微信应用的其他身份');
                    }
                    $this->persistence->createIdentity($context, [
                        'provider' => self::PROVIDER,
                        'client_key' => $clientKey,
                        'subject' => $profile->subject(),
                        'principal_id' => $principal?->id,
                        'member_id' => $member->id,
                        'terminal' => (int) $sceneMeta['terminal'],
                    ]);
                    $member = $this->updateProfile($context, $member, $profile);
                    return [$member, $created];
                }),
            );
        } catch (AdvisoryLockUnavailable) {
            throw BusinessException::conflict('OAUTH_LOGIN_BUSY', '微信登录正在处理中，请稍后重试');
        }
    }

    private function assertPrincipalOwnership(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        OAuthProfile $profile,
        int $memberId,
    ): ?int {
        if ($profile->unionId() === '') {
            return null;
        }
        $principal = $this->persistence->principalByUnionForUpdate(
            $context,
            self::PROVIDER,
            self::UNION_SCOPE,
            $profile->unionId(),
        );
        if ($principal !== null && $principal->memberId !== $memberId) {
            throw BusinessException::conflict('OAUTH_PRINCIPAL_OWNERSHIP_CONFLICT', '微信联合身份归属冲突，不能自动合并账号');
        }
        if ($principal === null) {
            $principal = $this->persistence->createPrincipal($context, [
                'provider' => self::PROVIDER,
                'union_scope' => self::UNION_SCOPE,
                'union_id' => $profile->unionId(),
                'member_id' => $memberId,
            ]);
        }
        return (int) $principal->id;
    }

    private function createMember(
        TenantContext|TenantSystemContext $context,
        OAuthProfile $profile,
        int $terminal,
    ): MemberIdentitySnapshot {
        return $this->memberIdentities->createOAuthMember($context, [
            'nickname' => $profile->nickname(),
            'avatar' => $profile->avatar() !== ''
                ? $profile->avatar()
                : $this->defaultAvatar($context),
            'channel' => $terminal,
        ]);
    }

    private function updateProfile(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        MemberIdentitySnapshot $member,
        OAuthProfile $profile,
    ): MemberIdentitySnapshot {
        $this->memberProfiles->fillOAuthProfile(
            $context,
            $member->id,
            $profile->nickname(),
            $profile->avatar(),
        );
        $updated = $this->members->identity($context, $member->id);
        if ($updated === null) {
            throw BusinessException::notFound('MEMBER_NOT_FOUND', '用户不存在');
        }
        return $updated;
    }

    private function defaultAvatar(TenantContext|TenantSystemContext $context): string
    {
        $avatar = trim((string) $this->applicationSettings->memberProfile($context)['user_avatar']);
        return $avatar !== '' ? $avatar : $this->defaultAvatar;
    }

    private function completionResult(
        TenantContext|TenantSystemContext $context,
        MemberIdentitySnapshot $member,
        bool $needProfile,
        bool $needMobile,
        ExternalTenantBinding $binding,
    ): OAuthLoginResult {
        $raw = bin2hex(random_bytes(32));
        $this->persistence->createCompletion($context, [
            'token_hash' => hash('sha256', $raw),
            'binding_id' => $binding->id,
            'member_id' => $member->id,
            'need_profile' => $needProfile ? 1 : 0,
            'need_mobile' => $needMobile ? 1 : 0,
            'expires_at' => time() + self::COMPLETION_TTL,
            'used_at' => null,
        ]);
        return new OAuthLoginResult(
            completed: false,
            member: $member,
            completionTicket: $raw,
            expiresIn: self::COMPLETION_TTL,
            needProfile: $needProfile,
            needMobile: $needMobile,
        );
    }

    private function fullLoginResult(MemberIdentitySnapshot $member): OAuthLoginResult
    {
        return new OAuthLoginResult(true, $member);
    }

    private function consumeAttempt(
        TenantSystemContext $context,
        string $scene,
        string $state,
    ): string {
        $state = trim($state);
        if ($state === '') {
            throw BusinessException::invalid('OAUTH_STATE_REQUIRED', '微信授权 state 缺失');
        }
        return Db::transaction(function () use ($context, $scene, $state): string {
            $attempt = $this->persistence->attemptForUpdate($context, hash('sha256', $state));
            if ($attempt === null || $attempt->scene !== $scene
                || $attempt->usedAt !== null || $attempt->expiresAt < time()) {
                throw BusinessException::invalid('OAUTH_STATE_INVALID', '微信授权 state 无效、已过期或已使用');
            }
            $this->persistence->markAttemptUsed($context, $attempt->id, time());
            return $attempt->returnPath;
        });
    }

    private static function assertReturnPath(string $path): void
    {
        if ($path === '' || strlen($path) > 500 || !str_starts_with($path, '/')
            || str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw BusinessException::invalid('OAUTH_RETURN_PATH_INVALID', '授权返回地址仅允许站内路径');
        }
        $parts = parse_url($path);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            throw BusinessException::invalid('OAUTH_RETURN_PATH_INVALID', '授权返回地址仅允许站内路径');
        }
    }
}
