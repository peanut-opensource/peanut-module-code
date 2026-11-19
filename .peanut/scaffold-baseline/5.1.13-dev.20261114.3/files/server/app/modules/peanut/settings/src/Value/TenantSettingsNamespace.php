<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Value;

final class TenantSettingsNamespace
{
    public static function assertValid(string $namespace): void
    {
        if (preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $namespace) !== 1) {
            throw new \InvalidArgumentException('租户设置命名空间无效');
        }
    }

    private function __construct() {}
}
