<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Provider;

use PeanutAdmin\DataPermission\Provider\TargetSetMembershipProvider;

/** The large target sets loaded by the current authorization decision. */
final class ThinkPhpTargetSetMembershipProvider implements TargetSetMembershipProvider
{
    /** @var array<int, array<int, list<string>>> */
    private array $sets = [];

    /** @param list<string> $ids */
    public function recordTargets(int $tenantId, int $targetSetId, array $ids): void
    {
        $this->sets[$tenantId][$targetSetId] = $ids;
    }

    /** @return list<string> */
    public function targets(int $tenantId, int $targetSetId): array
    {
        return $this->sets[$tenantId][$targetSetId] ?? [];
    }

    public function containsAll(int $tenantId, int $targetSetId, array $targetIds): bool
    {
        return isset($this->sets[$tenantId][$targetSetId])
            && array_diff($targetIds, $this->sets[$tenantId][$targetSetId]) === [];
    }
}
