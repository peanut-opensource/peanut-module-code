<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Sms;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;

/** Fail-closed until a verified employee mobile source is registered. */
final readonly class UnavailableSmsRecipientResolver implements SmsRecipientResolver
{
    public function resolve(TenantContext $context, int $memberId): SmsRecipient
    {
        throw NotificationException::recipientUnavailable();
    }
}
