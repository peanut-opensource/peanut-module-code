<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Contract;

use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;

/**
 * Host-facing lookup of unverified candidates from an existing OAuth state/ticket.
 * It grants no context, token or permission. The host must retain verifiedCandidates,
 * module checks and the owning OAuth command's atomic expiry/one-time consumption.
 */
interface OAuthCallbackLocator
{
    /** @return list<ExternalTenantBinding> */
    public function locateState(string $provider, string $stateHash): array;

    /** @return list<ExternalTenantBinding> */
    public function locateTicket(string $ticketHash): array;
}
