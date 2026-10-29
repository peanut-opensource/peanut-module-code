<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Service;

use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceMutation;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceSnapshot;
use PeanutAdmin\Modules\Member\Contract\MemberBalanceCommands;
use app\common\value\Money;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final class MemberBalanceContractService implements MemberBalanceCommands
{
    public function applyInTransaction(
        TenantContext|TenantSystemContext $context,
        MemberBalanceMutation $mutation,
    ): MemberBalanceSnapshot {
        $member = MemberBalanceService::applyInTransaction(
            $context,
            $mutation->memberId,
            $mutation->changeType,
            $mutation->action,
            $mutation->amountCents,
            $mutation->sourceSn,
            $mutation->remark,
            $mutation->extra,
            $mutation->adminId,
            $mutation->rechargeDeltaCents,
            $mutation->insufficientMessage,
        );

        return new MemberBalanceSnapshot(
            (int) $member->id,
            Money::toCents((string) $member->getData('user_money')),
            Money::toCents((string) $member->getData('total_recharge_amount')),
        );
    }
}
