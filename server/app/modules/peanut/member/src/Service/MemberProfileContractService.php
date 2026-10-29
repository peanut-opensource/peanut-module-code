<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Service;

use PeanutAdmin\Modules\Member\Model\Member;
use PeanutAdmin\Modules\Member\Model\MemberTag;
use PeanutAdmin\Modules\Member\Model\MemberTagRelation;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Member\Contract\MemberProfileCommands;
use PeanutAdmin\Modules\Member\Contract\MemberSessions;
use app\common\validate\MemberProfileSelfFieldValidate;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use app\common\support\PositiveIds;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use think\facade\Db;

final class MemberProfileContractService implements MemberProfileCommands
{
    public function __construct(private readonly ?MemberSessions $sessions = null) {}

    public function createAdminMember(TenantContext $context, array $profile, array $tagIds): void
    {
        $member = Member::create([
            'sn' => Member::generateSn($context),
            'nickname' => (string) $profile['nickname'],
            'avatar' => (string) ($profile['avatar'] ?? ''),
            'mobile' => (string) ($profile['mobile'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'sex' => (int) ($profile['sex'] ?? 0),
            'birthday' => $profile['birthday'] ?? null,
            'status' => (int) ($profile['status'] ?? 1),
        ]);
        $this->replaceTags($context, (int) $member->id, $tagIds);
    }

    public function updateAdminMember(TenantContext $context, int $memberId, array $profile, ?array $tagIds): void
    {
        // 管理端整档更新也必须使停用／修订／会话撤销原子完成，不能依赖外层碰巧有事务。
        Db::transaction(function () use ($context, $memberId, $profile, $tagIds): void {
            $disabled = array_key_exists('status', $profile) && (int) $profile['status'] === 0;
            $sessions = $disabled ? $this->sessions() : null;
            $member = $this->member($context, $memberId, $disabled);
            $data = [];
            foreach (['nickname', 'avatar', 'mobile', 'email', 'birthday'] as $field) {
                if (array_key_exists($field, $profile)) {
                    $data[$field] = $profile[$field];
                }
            }
            foreach (['sex', 'status'] as $field) {
                if (array_key_exists($field, $profile)) {
                    $data[$field] = (int) $profile[$field];
                }
            }
            if ($disabled) {
                $data['session_revision'] = (int) $member->getData('session_revision') + 1;
            }
            if ($data !== []) {
                $member->save($data);
            }
            if ($disabled) {
                $sessions->revokeAll($context->tenantId, $memberId, 'member_disabled', time());
            }
            if ($tagIds !== null) {
                $this->replaceTags($context, $memberId, $tagIds);
            }
        });
    }

    public function updateAdminField(TenantContext $context, int $memberId, string $field, mixed $value): void
    {
        if ($field === 'status' && (int) $value === 0) {
            $this->disableMember($context, $memberId);
            return;
        }
        $this->member($context, $memberId)->save([$field => $value]);
    }

    public function updateStatus(TenantContext $context, int $memberId, int $status): void
    {
        if ($status === 0) {
            $this->disableMember($context, $memberId);
            return;
        }
        $this->member($context, $memberId)->save(['status' => $status]);
    }

    public function updateSelfField(AuthenticatedMemberContext|TenantContext $context, int $memberId, string $field, mixed $value): void
    {
        if ($context instanceof AuthenticatedMemberContext && $context->memberId !== $memberId) {
            throw BusinessException::forbidden('MEMBER_PROFILE_SELF_FORBIDDEN', '只能修改自己的资料');
        }
        $value = MemberProfileSelfFieldValidate::normalize($field, $value);
        // 模型保存可区分「不存在」和「值未变化」：重复提交清空请求仍应成功。
        $this->member($context, $memberId)->save([$field => $value]);
    }

    public function completeOAuthProfile(
        TenantContext|TenantSystemContext $context,
        int $memberId,
        ?string $nickname,
        ?string $avatar,
        int $loginTime,
        string $loginIp,
    ): void {
        $member = $this->member($context, $memberId);
        if ($nickname !== null) {
            $member->nickname = $nickname;
        }
        if ($avatar !== null) {
            $member->avatar = $avatar;
        }
        $member->is_new_user = 0;
        $member->login_time = $loginTime;
        $member->login_ip = $loginIp;
        $member->save();
    }

    public function fillOAuthProfile(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, int $memberId, string $nickname, string $avatar): void
    {
        $member = $this->member($context, $memberId);
        $data = [];
        if ($nickname !== '' && trim((string) $member->nickname) === '') {
            $data['nickname'] = mb_substr($nickname, 0, 50);
        }
        if ($avatar !== '' && trim((string) $member->avatar) === '') {
            $data['avatar'] = $avatar;
        }
        if ($data !== []) {
            $member->save($data);
        }
    }

    private function replaceTags(TenantContext $context, int $memberId, array $tagIds): void
    {
        $member = $this->member($context, $memberId);
        $tagIds = PositiveIds::normalize($tagIds, [PositiveIds::REJECT_INVALID], '包含不存在的会员标签');
        if ($tagIds !== [] && MemberTag::where([])->whereIn('id', $tagIds)->count() !== count($tagIds)) {
            throw BusinessException::invalid('MEMBER_TAG_SELECTION_INVALID', '包含不存在的会员标签');
        }
        MemberTagRelation::where([])->where('member_id', $memberId)->delete();
        if ($tagIds !== []) {
            (new MemberTagRelation())->saveAll(array_map(
                static fn(int $tagId): array => ['member_id' => (int) $member->id, 'tag_id' => $tagId],
                $tagIds,
            ));
        }
    }

    private function member(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
        bool $forUpdate = false,
    ): object {
        $query = Member::where([])->where('id', $memberId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $member = $query->findOrEmpty();
        if ($member->isEmpty()) {
            throw BusinessException::notFound('MEMBER_NOT_FOUND', '用户不存在');
        }
        return $member;
    }

    private function disableMember(TenantContext $context, int $memberId): void
    {
        $sessions = $this->sessions();
        Db::transaction(function () use ($context, $memberId, $sessions): void {
            $member = $this->member($context, $memberId, true);
            $member->status = 0;
            $member->session_revision = (int) $member->getData('session_revision') + 1;
            $member->save();
            $sessions->revokeAll($context->tenantId, $memberId, 'member_disabled', time());
        });
    }

    private function sessions(): MemberSessions
    {
        return $this->sessions ?? throw new \LogicException('MEMBER_SESSIONS_UNAVAILABLE');
    }
}
