<?php

declare(strict_types=1);

namespace app\common\enum\instance;

enum DeploymentMode: string
{
    case Standalone = 'standalone';
    case MultiTenant = 'multi-tenant';

    public static function fromConfiguredValue(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }

        return self::tryFrom(trim($value));
    }
}
