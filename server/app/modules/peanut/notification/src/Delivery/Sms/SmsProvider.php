<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Sms;

interface SmsProvider
{
    public function key(): string;

    /**
     * The provider must deduplicate retries by SmsSendRequest::idempotencyKey().
     * Timeouts with an uncertain remote result must remain retryable/unknown;
     * callers reconcile the returned receipt using that same key.
     */
    public function send(SmsSendRequest $request): SmsReceipt;
}
