<?php

declare(strict_types=1);

namespace app\platform\services;

use app\common\services\authorization\EndpointPermissionGrant;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;

/** Preserves direct/worker RBAC while reusing the current HTTP endpoint decision. */
final readonly class PlatformApiAuthorization
{
    public function __construct(private PlatformAuthorizationEvaluator $roles, private EndpointPermissionGrant $endpoint) {}

    public function allows(PlatformContext $context, string $permissionKey): bool
    {
        return $this->endpoint->allows($context, $permissionKey) || $this->roles->allows($context, $permissionKey);
    }

    public function assertAllowed(PlatformContext $context, string $permissionKey): void
    {
        if (!$this->allows($context, $permissionKey)) {
            throw new AuthorizationException();
        }
    }
}
