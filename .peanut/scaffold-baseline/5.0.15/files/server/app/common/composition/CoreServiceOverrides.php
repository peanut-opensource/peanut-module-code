<?php

declare(strict_types=1);

namespace app\common\composition;

use app\common\contract\AdminPermissionPolicy;
use app\common\policy\permission\RegisteredAdminPermissionPolicy;
use PeanutAdmin\Kernel\Override\ServiceOverride;
use PeanutAdmin\Kernel\Override\ServiceOverrideRegistry;
use PeanutAdmin\Kernel\Override\ServiceOverrideSlot;

/** Versioned permission-policy slots; resolved bindings use the native framework container. */
final class CoreServiceOverrides
{
    public const ADMIN_PERMISSION_POLICY = 'authorization.permission.service.policy';
    public const CONTRACT_VERSION = '2.0.0';

    private static ?ServiceOverrideRegistry $registry = null;
    private static array $configuredOverrides = [];

    public static function configure(array $overrides): void
    {
        self::$configuredOverrides = $overrides;
        self::$registry = null;
    }

    public static function registry(): ServiceOverrideRegistry
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $overrides = [];
        foreach (self::$configuredOverrides as $key => $implementation) {
            if (!is_string($key) || !is_string($implementation)) {
                throw new \RuntimeException('Peanut 核心服务覆盖配置无效');
            }
            if ($key !== self::ADMIN_PERMISSION_POLICY) {
                throw new \RuntimeException('未知的 Peanut 核心服务覆盖：' . $key);
            }
            $overrides[] = new ServiceOverride(
                $key,
                AdminPermissionPolicy::class,
                self::CONTRACT_VERSION,
                $implementation,
            );
        }

        self::$registry = new ServiceOverrideRegistry([
            new ServiceOverrideSlot(
                self::ADMIN_PERMISSION_POLICY,
                AdminPermissionPolicy::class,
                self::CONTRACT_VERSION,
                RegisteredAdminPermissionPolicy::class,
            ),
        ], $overrides);

        return self::$registry;
    }
}
