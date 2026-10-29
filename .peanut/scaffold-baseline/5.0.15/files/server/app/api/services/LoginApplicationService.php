<?php

declare(strict_types=1);

namespace app\api\services;

use PeanutAdmin\Modules\Notification\Contract\VerificationCodeCommands;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberIdentitySnapshot;
use PeanutAdmin\Modules\Member\Contract\MemberIdentityCommands;
use app\api\services\UserTokenService;
use app\common\enum\notice\NoticeSceneEnum;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

class LoginApplicationService
{
    public function __construct(
        private readonly MemberIdentityCommands $memberIdentities,
        private readonly TenantApplicationSettings $applicationSettings,
        private readonly FileReferences $files,
        private readonly UserTokenService $tokens,
        private readonly string $defaultAvatar,
        private readonly ?VerificationCodeCommands $verificationCodes = null,
    ) {}

    /**
     * 账号注册
     * params: account, password, scene(默认h5)
     */
    public function register(TenantSystemContext $context, array $params): bool
    {
        $this->assertLoginWayEnabled($context, 1);
        $this->memberIdentities->register(
            $context,
            (string) $params['account'],
            (string) $params['password'],
            $this->defaultAvatar($context),
        );

        return true;
    }

    /**
     * 账号/手机号 + 密码登录
     * params: account(账号或手机号), password, terminal
     */
    public function login(TenantSystemContext $context, array $params, string $ip): array
    {
        $this->assertLoginWayEnabled($context, 1);
        $member = $this->memberIdentities->login(
            $context,
            (string) $params['account'],
            (string) $params['password'],
            $ip,
        );

        $token  = $this->tokens->createToken($member->id);
        $avatar = $this->files->getFileUrl((string) $member->avatar);

        return [
            'token'    => $token,
            'id'       => $member->id,
            'sn'       => $member->sn,
            'nickname' => $member->nickname,
            'avatar'   => $avatar,
            'mobile'   => $member->mobile,
        ];
    }

    public function mobileLogin(TenantContext|TenantSystemContext $context, array $params, string $ip): array
    {
        $this->assertLoginWayEnabled($context, 2);
        $mobile = (string) $params['mobile'];
        $result = ($this->verificationCodes ?? throw new \DomainException('NOTIFICATION_MODULE_UNAVAILABLE'))->verifyCode(
            $context,
            NoticeSceneEnum::LOGIN_CODE,
            $mobile,
            (string) $params['code'],
        );
        if (!$result->accepted) {
            throw BusinessException::invalid('MEMBER_VERIFICATION_REJECTED', $result->error);
        }

        $member = $this->memberIdentities->loginByVerifiedMobile(
            $context,
            $mobile,
            $this->defaultAvatar($context),
            $ip,
        );
        return $this->loginResult($member, false);
    }

    public function resetPassword(TenantContext|TenantSystemContext $context, array $params): bool
    {
        $this->memberIdentities->assertMobileBound($context, (string) $params['mobile']);
        $result = ($this->verificationCodes ?? throw new \DomainException('NOTIFICATION_MODULE_UNAVAILABLE'))->verifyCode(
            $context,
            NoticeSceneEnum::RESET_PASSWORD,
            (string) $params['mobile'],
            (string) $params['code'],
        );
        if (!$result->accepted) {
            throw BusinessException::invalid('MEMBER_VERIFICATION_REJECTED', $result->error);
        }

        $this->memberIdentities->resetPasswordByVerifiedMobile(
            $context,
            (string) $params['mobile'],
            (string) $params['password'],
        );
        return true;
    }

    private function loginResult(MemberIdentitySnapshot $member, bool $recordLogin = true): array
    {
        if ($recordLogin) {
            throw new \LogicException('Member login must be recorded by MemberIdentityCommands');
        }

        return [
            'token'    => $this->tokens->createToken((int) $member->id),
            'id'       => $member->id,
            'sn'       => $member->sn,
            'nickname' => $member->nickname,
            'avatar'   => $this->files->getFileUrl((string) $member->avatar),
            'mobile'   => $member->mobile,
        ];
    }

    private function assertLoginWayEnabled(
        TenantContext|TenantSystemContext $context,
        int $way,
    ): void {
        $enabled = $this->applicationSettings->login($context)['login_way'];
        if (!in_array($way, $enabled, true)) {
            throw BusinessException::forbidden('MEMBER_LOGIN_WAY_DISABLED', '当前登录方式未启用');
        }
    }

    private function defaultAvatar(TenantContext|TenantSystemContext $context): string
    {
        $avatar = trim((string) $this->applicationSettings->memberProfile($context)['user_avatar']);
        return $avatar !== '' ? $avatar : $this->defaultAvatar;
    }

    /** 仅撤销当前会员端会话；同账号其他终端保持在线。 */
    public function logout(string $token): bool
    {
        $this->tokens->revokeToken($token);
        return true;
    }
}
