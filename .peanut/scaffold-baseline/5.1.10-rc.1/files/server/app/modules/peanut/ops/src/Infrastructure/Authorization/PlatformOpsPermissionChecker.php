<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure\Authorization;

use PeanutAdmin\Kernel\Context\PlatformContext;
use app\platform\services\PlatformApiAuthorization;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;

/** Core Ops permission adapter over the application's canonical Platform RBAC. */
final readonly class PlatformOpsPermissionChecker implements PlatformPermissionChecker
{
    public function __construct(private PlatformApiAuthorization $evaluator) {}

    public function allows(PlatformContext $context, string $permissionKey): bool
    {
        return $this->evaluator->allows($context, $permissionKey);
    }
}
