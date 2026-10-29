<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Service;

use PeanutAdmin\Modules\Member\Model\Member;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberIdentitySnapshot;
use PeanutAdmin\Modules\Member\Contract\MemberIdentityCommands;
use PeanutAdmin\Modules\Member\Contract\MemberSessions;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use think\facade\Db;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;

/** Owns member credential creation, verification, and identity snapshots within one Tenant. */
final class MemberIdentityContractService implements MemberIdentityCommands
{
    public function __construct(
        private readonly PasswordHasher $passwords,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly ?MemberSessions $sessions = null,
    ) {}

    public function register(TenantSystemContext $context, string $account, string $password, string $avatar): void
    {
        if (Member::where([])->where('account', $account)->count() > 0) {
            throw new \runtimeException('账号已被注册');
        }
        $sn = Member::generateSn($context);
        Member::create([
            'sn' => $sn,
            'account' => $account,
            'password' => $this->passwordHash($password),
            'nickname' => '用户' . substr($sn, -6),
            'avatar' => $avatar,
            'status' => 1,
        ]);
    }

    public function login(TenantSystemContext $context, string $identifier, string $password, string $loginIp): MemberIdentitySnapshot
    {
        $member = Member::where([])->where(function ($query) use ($identifier): void {
            $query->where('account', $identifier)->whereOr('mobile', $identifier);
        })->findOrEmpty();
        if ($member->isEmpty()) {
            throw new \runtimeException('账号不存在');
        }
        if (!(int) $member->status) {
            throw new \runtimeException('账号已被禁用');
        }
        if (!$this->passwordMatches((string) $member->password, $password)) {
            throw new \runtimeException('密码错误');
        }
        if ($this->passwords->needsRehash((string) $member->password)) {
            // A successful legacy login is the only point where the plaintext is available for one-time migration.
            $member->password = $this->passwords->hash($password);
        }
        $member->login_time = time();
        $member->login_ip = $loginIp;
        $member->save();
        return self::snapshot($member);
    }

    public function loginByVerifiedMobile(
        TenantContext|TenantSystemContext $context,
        string $mobile,
        string $avatar,
        string $loginIp,
    ): MemberIdentitySnapshot {
        $member = Member::where([])->where('mobile', $mobile)->findOrEmpty();
        if ($member->isEmpty()) {
            $sn = Member::generateSn($context);
            $member = Member::create([
                'sn' => $sn,
                'account' => $mobile,
                'password' => '',
                'mobile' => $mobile,
                'nickname' => '用户' . substr($sn, -6),
                'avatar' => $avatar,
                'status' => 1,
            ]);
        }
        if (!(int) $member->status) {
            throw new \runtimeException('账号已被禁用');
        }
        $member->login_time = time();
        $member->login_ip = $loginIp;
        $member->save();
        return self::snapshot($member);
    }

    public function resetPasswordByVerifiedMobile(
        TenantContext|TenantSystemContext $context,
        string $mobile,
        string $password,
    ): void {
        $sessions = $this->sessions();
        Db::transaction(function () use ($context, $mobile, $password, $sessions): void {
            $member = Member::where([])->where('mobile', $mobile)->lock(true)->findOrEmpty();
            if ($member->isEmpty()) {
                throw new \runtimeException('手机号未绑定账号');
            }
            $member->password = $this->passwordHash($password);
            $member->session_revision = (int) $member->getData('session_revision') + 1;
            $member->save();
            $sessions->revokeAll($context->tenantId, (int) $member->id, 'password_reset', time());
        });
    }

    public function assertMobileBound(TenantContext|TenantSystemContext $context, string $mobile): void
    {
        if (Member::where([])->where('mobile', $mobile)->findOrEmpty()->isEmpty()) {
            throw new \runtimeException('手机号未绑定账号');
        }
    }

    public function assertMobileAvailable(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
        string $mobile,
    ): void {
        if (!Member::where([])->where('mobile', $mobile)
            ->where('id', '<>', $memberId)->lock(true)->findOrEmpty()->isEmpty()) {
            throw new \runtimeException('手机号已被其他账号绑定');
        }
    }

    public function changePassword(AuthenticatedMemberContext $context, int $memberId, string $oldPassword, string $newPassword): void
    {
        if ($context->memberId !== $memberId) {
            throw new \runtimeException('只能修改自己的密码');
        }
        $sessions = $this->sessions();
        Db::transaction(function () use ($context, $memberId, $oldPassword, $newPassword, $sessions): void {
            $member = Member::where([])->where('id', $memberId)->lock(true)->findOrEmpty();
            if ($member->isEmpty()) {
                throw new \runtimeException('用户不存在');
            }
            if (!$this->passwordMatches((string) $member->password, $oldPassword)) {
                throw new \runtimeException('原密码错误');
            }
            $member->password = $this->passwordHash($newPassword);
            $member->session_revision = (int) $member->getData('session_revision') + 1;
            $member->save();
            $sessions->revokeAll($context->tenantId, $memberId, 'password_change', time());
        });
    }

    public function bindVerifiedMobile(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, int $memberId, string $mobile): void
    {
        $this->assertMobileAvailable($context, $memberId, $mobile);
        if (Member::where([])->where('id', $memberId)->update(['mobile' => $mobile]) !== 1) {
            throw new \runtimeException('用户不存在');
        }
    }

    public function createOAuthMember(TenantContext|TenantSystemContext $context, array $profile): MemberIdentitySnapshot
    {
        $sn = Member::generateSn($context);
        do {
            $account = 'wx_' . strtolower(bin2hex(random_bytes(6)));
        } while (Member::where([])->withTrashed()->where('account', $account)->count() > 0);
        $member = Member::create([
            'sn' => $sn,
            'account' => $account,
            'password' => '',
            'nickname' => mb_substr(
                (string) $profile['nickname'] !== '' ? (string) $profile['nickname'] : ('微信用户' . substr($sn, -6)),
                0,
                50,
            ),
            'avatar' => (string) $profile['avatar'],
            'mobile' => '',
            'channel' => (int) $profile['channel'],
            'is_new_user' => 1,
            'status' => 1,
        ]);
        return self::snapshot($member);
    }

    public function recordLogin(TenantContext|TenantSystemContext $context, int $memberId, string $loginIp): void
    {
        if (Member::where([])->where('id', $memberId)->update([
            'login_time' => time(),
            'login_ip' => $loginIp,
        ]) !== 1) {
            throw new \runtimeException('用户不存在');
        }
    }

    private function passwordMatches(string $stored, string $password): bool
    {
        if (($info = password_get_info($stored))['algoName'] !== 'unknown') {
            return $this->passwords->verify($password, $stored);
        }

        [$hash, $salt] = array_pad(explode(':', $stored, 2), 2, '');
        if (preg_match('/^[a-f0-9]{32}$/D', $hash) !== 1
            || preg_match('/^[a-f0-9]{8}$/D', $salt) !== 1) {
            return false;
        }
        return hash_equals($hash, md5(md5($password) . $salt));
    }

    private function sessions(): MemberSessions
    {
        return $this->sessions ?? throw new \LogicException('MEMBER_SESSIONS_UNAVAILABLE');
    }

    /** Creates the only supported hash format for new or changed member passwords. */
    private function passwordHash(string $password): string
    {
        $this->passwordPolicy->assertValid($password);
        return $this->passwords->hash($password);
    }

    private static function snapshot(object $member): MemberIdentitySnapshot
    {
        return new MemberIdentitySnapshot(
            (int) $member->id,
            (string) $member->sn,
            (string) $member->nickname,
            (string) $member->avatar,
            (string) $member->mobile,
            (int) $member->status,
        );
    }
}
