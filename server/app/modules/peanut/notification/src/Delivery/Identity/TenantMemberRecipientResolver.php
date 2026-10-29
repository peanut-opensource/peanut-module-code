<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Identity;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Application\RecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Application\RecipientSnapshot;

/** Resolves notification recipients through Identity's public membership projection. */
final readonly class TenantMemberRecipientResolver implements RecipientResolver
{
    public function __construct(private TenantMemberDirectory $members) {}

    public function snapshot(TenantContext $context, int $memberId, bool $requiresSms): RecipientSnapshot
    {
        $member = $this->members->activeMember($context, $memberId);
        if ($member === null || $requiresSms) {
            // Identity currently owns no verified employee mobile credential.
            throw NotificationException::recipientUnavailable();
        }

        return new RecipientSnapshot(
            $member->memberId,
            $member->accountId,
            $member->displayName,
            null,
            null,
        );
    }
}
