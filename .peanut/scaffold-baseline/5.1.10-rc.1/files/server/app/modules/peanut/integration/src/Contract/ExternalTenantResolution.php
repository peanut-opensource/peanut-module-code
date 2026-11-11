<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

use PeanutAdmin\Kernel\Context\TenantSystemContext;

final readonly class ExternalTenantResolution
{
    public function __construct(
        public TenantSystemContext $context,
        public ExternalTenantBinding $binding,
        public mixed $verifiedValue = null,
    ) {}
}
