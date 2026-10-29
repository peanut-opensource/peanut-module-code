<?php

declare(strict_types=1);

namespace app\platform\services;

use app\platform\context\PlatformOperatorContext;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformWorkspaceQueryService;

final readonly class PlatformTenantQueryService
{
    private const READ_PERMISSION = 'platform.tenant.read';

    public function __construct(
        private PlatformOperatorSessionService $sessions,
        private PlatformWorkspaceQueryService $workspace,
    ) {}

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function tenants(PlatformOperatorContext $context, PageRequest $page): array
    {
        $this->sessions->assertAllowed($context, self::READ_PERMISSION);
        return $this->workspace->tenants($page);
    }

    /** @return array<string,mixed> */
    public function tenant(PlatformOperatorContext $context, int $tenantId): array
    {
        $this->sessions->assertAllowed($context, self::READ_PERMISSION);
        return $this->workspace->tenant($tenantId);
    }
}
