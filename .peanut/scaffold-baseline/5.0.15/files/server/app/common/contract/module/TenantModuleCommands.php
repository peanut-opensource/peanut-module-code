<?php

declare(strict_types=1);

namespace app\common\contract\module;

use DateTimeImmutable;

interface TenantModuleCommands
{
    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function enable(
        string $operatorCredential,
        int $tenantId,
        string $moduleKey,
        array $config,
        string $source,
        ?DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        string $changeReason,
        string $requestId,
    ): array;

    /** @return array<string,mixed> */
    public function disable(
        string $operatorCredential,
        int $tenantId,
        string $moduleKey,
        string $changeReason,
        string $requestId,
    ): array;
}
