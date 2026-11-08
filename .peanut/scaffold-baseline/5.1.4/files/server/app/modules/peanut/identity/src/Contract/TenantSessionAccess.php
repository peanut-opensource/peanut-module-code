<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantSessionSummary;

/** Self-service session query and revocation owned by Identity. */
interface TenantSessionAccess
{
    /** Revalidate a server-held session reference; never hydrate a client-supplied identity. */
    public function revalidateSession(int $tenantId, int $accountId, int $memberId, string $sessionKey, string $clientKey, string $requestId): TenantContext;

    /** @return list<TenantSessionSummary> */
    public function ownedSessions(TenantContext $context): array;

    public function revokeOwnedSession(TenantContext $context, string $sessionKey): TenantSessionSummary;
}
