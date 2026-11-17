<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface MachineScopeGrantResolver
{
    /** @return list<string> */
    public function grantableScopes(AuthorizedOperationContext $context): array;
}
