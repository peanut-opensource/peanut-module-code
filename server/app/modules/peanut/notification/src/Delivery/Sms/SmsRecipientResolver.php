<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Sms;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface SmsRecipientResolver
{
    /** Resolves transient delivery data for an active member in one Tenant. */
    public function resolve(TenantContext $context, int $memberId): SmsRecipient;
}
