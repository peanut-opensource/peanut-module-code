<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\EntitlementQuota;

final class Package
{
    public const MODULE_KEY = 'peanut.entitlement-quota';
    public const VERSION = '4.0.0-dev';

    public const CHECK_OPERATION = 'entitlement-quota.check';
    public const RESERVE_OPERATION = 'entitlement-quota.reserve';
    public const COMMIT_OPERATION = 'entitlement-quota.commit';
    public const RELEASE_OPERATION = 'entitlement-quota.release';
    public const USAGE_OPERATION = 'entitlement-quota.usage';

    private function __construct() {}
}
