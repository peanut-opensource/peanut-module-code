<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Invitation;

interface OwnerInvitationDeliveryPort
{
    public function isConfigured(): bool;

    public function deliver(OwnerInvitationDelivery $delivery): OwnerInvitationDeliveryResult;
}
