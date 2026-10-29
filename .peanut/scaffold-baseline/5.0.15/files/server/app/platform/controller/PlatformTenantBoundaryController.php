<?php

declare(strict_types=1);

namespace app\platform\controller;

final class PlatformTenantBoundaryController extends BasePlatformController
{
    public function capabilities()
    {
        return $this->data([
            'audience' => 'platform',
            'scope' => 'application-instance',
            'permission_catalog' => 'platform.*',
            'tenant_business_access' => false,
            'operations' => [
                'platform.tenant.create',
                'platform.tenant.lifecycle',
                'platform.tenant.provision-owner',
                'platform.tenant.module.manage',
            ],
        ]);
    }
}
