<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceMutation;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceSnapshot;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface MemberBalanceCommands
{
    /**
     * The caller must already own the database transaction containing its
     * domain-state change. This command locks the Tenant-scoped member row,
     * writes the balance change and appends its ledger row in that transaction.
     */
    public function applyInTransaction(
        TenantContext|TenantSystemContext $context,
        MemberBalanceMutation $mutation,
    ): MemberBalanceSnapshot;
}
