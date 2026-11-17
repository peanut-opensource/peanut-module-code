<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use think\facade\Db;

/** Owner-provided public name projection only; not tenant selection or an authorization grant. */
final class TenantIdentityQuery
{
    public function activeName(int $tenantId): string
    {
        if ($tenantId < 1) {
            return '';
        }
        return trim((string) Db::name('tenant')->where('id', $tenantId)->where('status', 'active')->value('name'));
    }
}
