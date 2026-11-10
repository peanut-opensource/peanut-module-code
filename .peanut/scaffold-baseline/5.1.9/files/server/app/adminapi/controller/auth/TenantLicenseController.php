<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

final class TenantLicenseController extends BaseAdminController
{
    public function detail()
    {
        $tenantId = $this->tenantAdminContext()->tenantId;
        return $this->data(['tenant_id' => $tenantId, 'license' => app(TenantEntitlementService::class)->license($tenantId)]);
    }
}
