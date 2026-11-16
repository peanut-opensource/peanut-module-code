<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Infrastructure;

use PeanutAdmin\Modules\Integration\Contract\MachineScopeGrantResolver;
use PeanutAdmin\Modules\Integration\Package;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class ConfiguredMachineScopeGrantResolver implements MachineScopeGrantResolver
{
    /** @param list<string> $scopes */
    public function __construct(private array $scopes) {}

    public function grantableScopes(AuthorizedOperationContext $context): array
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey)
            || !hash_equals('machine-manage', $context->operation)
        ) {
            return [];
        }
        return $this->scopes;
    }
}
