<?php

declare(strict_types=1);

namespace app\api\services;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** Host-owned configuration composition, shared by every PC application. */
final readonly class PcApplicationService
{
    public function __construct(private IndexApplicationService $index) {}

    public function config(
        TenantContext|TenantSystemContext $context,
        string $domain,
        string $host,
        ?int $entryTenantId,
    ): array {
        return $this->index->getConfigData($context, $domain, $host, $entryTenantId);
    }
}
