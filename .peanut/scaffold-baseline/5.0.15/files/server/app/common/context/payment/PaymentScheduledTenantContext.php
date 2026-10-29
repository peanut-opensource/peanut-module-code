<?php

declare(strict_types=1);

namespace app\common\context\payment;

use PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

/** Payment-owned adapter for scheduled reconciliation context. */
final class PaymentScheduledTenantContext
{
    public static function require(): TenantScope
    {
        return ScheduledTenantContext::require();
    }
}
