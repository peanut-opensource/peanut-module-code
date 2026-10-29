<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Service;

use PeanutAdmin\Modules\Member\Model\Member;
use PeanutAdmin\Modules\Member\Model\MemberBalanceLog;
use PeanutAdmin\Modules\Member\Model\MemberTag;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceSnapshot;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberIdentitySnapshot;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use app\common\http\PageResult;
use app\common\execution\CurrentExecutionContext;
use app\common\value\Money;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use app\common\support\PaginationInput;

final class MemberQueryService implements MemberQueries
{
    public function __construct(private readonly CurrentExecutionContext $executionContext) {}

    public function identity(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
    ): ?MemberIdentitySnapshot {
        return $this->identitySnapshot($context, $memberId, false);
    }

    public function lockedIdentity(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
    ): ?MemberIdentitySnapshot {
        return $this->identitySnapshot($context, $memberId, true);
    }

    public function memberFields(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
        array $fields,
    ): array {
        $member = Member::where([])
            ->field($fields)->findOrEmpty($memberId);
        if ($member->isEmpty()) {
            return [];
        }
        $data = $member->toArray();
        if (in_array('password', $fields, true)) {
            $data['password'] = (string) $member->getData('password');
        }
        return $data;
    }

    public function tags(TenantContext|TenantSystemContext $context): array
    {
        return MemberTag::where([])->order('id', 'desc')->select()->toArray();
    }

    public function balanceSnapshot(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
    ): ?MemberBalanceSnapshot {
        $member = Member::where([])->findOrEmpty($memberId);
        if ($member->isEmpty()) {
            return null;
        }

        return new MemberBalanceSnapshot(
            (int) $member->id,
            Money::toCents((string) $member->getData('user_money')),
            Money::toCents((string) $member->getData('total_recharge_amount')),
        );
    }

    public function balanceLogsForCurrentMember(int $page, int $pageSize): PageResult
    {
        $context = $this->executionContext->member();
        $memberId = $context->memberId;
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $query = MemberBalanceLog::where([])->where('member_id', $memberId);
        $pageResult = PaginationInput::from([
            'page_no' => $page,
            'page_size' => $pageSize,
        ])->result($query->order('id', 'desc'));

        return $pageResult->map(static fn(mixed $item): array => $item instanceof \think\Model
            ? $item->toArray()
            : (array) $item);
    }

    private function identitySnapshot(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
        bool $lock,
    ): ?MemberIdentitySnapshot {
        $query = Member::where([])->where('id', $memberId);
        if ($lock) {
            $query->lock(true);
        }
        $member = $query->findOrEmpty();
        return $member->isEmpty() ? null : self::snapshot($member);
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
