<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

/** Immutable negative facts. Reading them grants no member, Document or system authority. */
interface RevocationFacts
{
    public function revocationFact(int $tenantId, string $factId, string $hash): array;
    public function revocationFacts(int $tenantId, int $afterId, int $limit): array;
}
