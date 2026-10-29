<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\EntitlementQuota\Contract;

interface EntitlementMeterRegistry
{
    public function find(string $meterKey, string $targetType): ?EntitlementMeter;
}
