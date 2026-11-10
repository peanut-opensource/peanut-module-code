<?php

declare(strict_types=1);

namespace app\common\validate;

use LogicException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\Validate;

/**
 * Base validator for rules that require a trusted tenant context.
 *
 * Existing validators are intentionally not migrated as part of the CRUD
 * pilot. New or migrated validators may call requireTenantContext() from
 * custom validation rules after the controller binds the request context.
 */
abstract class TenantContextValidate extends Validate
{
    private ?TenantContext $tenantContext = null;

    final public function forTenant(TenantContext $context): static
    {
        $this->tenantContext = $context;
        return $this;
    }

    final protected function requireTenantContext(): TenantContext
    {
        return $this->tenantContext
            ?? throw new LogicException('Tenant context is required.');
    }
}
