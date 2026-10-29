<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Service;

use PeanutAdmin\Modules\Member\Model\Member;
use PeanutAdmin\Modules\Member\Model\MemberBalanceLog;
use app\common\exception\BusinessException;
use app\common\enum\AccountLogEnum;
use app\common\value\Money;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** 会员余额和分类流水的唯一写入入口。 */
final class MemberBalanceService
{
    /** 调用方必须已开启包含其领域状态变更的数据库事务。 */
    public static function applyInTransaction(
        TenantContext|TenantSystemContext $context,
        int $memberId,
        int $changeType,
        int $action,
        int $amountCents,
        string $sourceSn = '',
        string $remark = '',
        array $extra = [],
        int $adminId = 0,
        int $rechargeDeltaCents = 0,
        string $insufficientMessage = '',
    ): object {
        if ($amountCents <= 0) {
            throw BusinessException::invalid('MEMBER_BALANCE_AMOUNT_INVALID', '调整金额必须大于零');
        }
        if (!in_array($action, [AccountLogEnum::INC, AccountLogEnum::DEC], true)) {
            throw BusinessException::invalid('MEMBER_BALANCE_ACTION_INVALID', '余额变动方向无效');
        }

        $member = Member::where([])->lock(true)->findOrEmpty($memberId);
        if ($member->isEmpty()) {
            throw BusinessException::notFound(
                'MEMBER_NOT_FOUND',
                $insufficientMessage !== '' ? $insufficientMessage : '用户不存在',
            );
        }

        $currentCents = Money::toCents((string) $member->getData('user_money'));
        $afterCents = $currentCents + ($action === AccountLogEnum::INC ? $amountCents : -$amountCents);
        if ($afterCents < 0) {
            throw BusinessException::conflict(
                'MEMBER_BALANCE_INSUFFICIENT',
                $insufficientMessage !== ''
                    ? $insufficientMessage
                    : '用户可用余额仅剩' . (float) $member->getData('user_money'),
            );
        }

        $rechargeCents = Money::toCents((string) $member->getData('total_recharge_amount'));
        $afterRechargeCents = $rechargeCents + $rechargeDeltaCents;
        if ($afterRechargeCents < 0) {
            throw BusinessException::conflict(
                'MEMBER_RECHARGE_TOTAL_INSUFFICIENT',
                $insufficientMessage !== '' ? $insufficientMessage : '累计充值金额不足',
            );
        }

        $afterMoney = Money::fromCents($afterCents);
        $member->user_money = $afterMoney;
        if ($rechargeDeltaCents !== 0) {
            $member->total_recharge_amount = Money::fromCents($afterRechargeCents);
        }
        $member->save();

        self::appendBalanceLog(
            $context,
            $memberId,
            $changeType,
            $action,
            $amountCents,
            $sourceSn,
            $remark,
            $extra,
            $adminId,
            $afterMoney,
        );

        return $member;
    }

    private static function appendBalanceLog(
        TenantContext|TenantSystemContext $context,
        int $memberId,
        int $changeType,
        int $action,
        int $amountCents,
        string $sourceSn,
        string $remark,
        array $extra,
        int $adminId,
        string $leftAmount,
    ): void {
        $changeObject = AccountLogEnum::getChangeObject($changeType);
        if ($changeObject === false) {
            throw BusinessException::invalid('MEMBER_BALANCE_CHANGE_TYPE_INVALID', '账户流水变动类型无效');
        }

        MemberBalanceLog::create([
            'sn' => MemberBalanceLog::generateSn($context),
            'member_id' => $memberId,
            'change_object' => $changeObject,
            'change_type' => $changeType,
            'action' => $action,
            'change_amount' => Money::fromCents($amountCents),
            'left_amount' => $leftAmount,
            'source_type' => 0,
            'source_sn' => $sourceSn,
            'remark' => $remark,
            'extra' => $extra === [] ? '' : json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'admin_id' => $adminId,
        ]);
    }
}
