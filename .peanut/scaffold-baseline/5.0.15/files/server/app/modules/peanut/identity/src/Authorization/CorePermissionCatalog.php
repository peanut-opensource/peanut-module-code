<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization;

final class CorePermissionCatalog
{
    /** @var list<string> */
    public const TENANT = [
        'workbench/index',
        'core.member.read',
        'core.member.effective-access.read',
        'core.member.create',
        'core.member.update',
        'core.member.role.assign',
        'core.member.suspend',
        'core.member.activate',
        'core.member.leave',
        'core.department.read',
        'core.department.create',
        'core.department.update',
        'core.department.move',
        'core.department.archive',
        'core.job.read',
        'core.job.create',
        'core.job.update',
        'core.job.archive',
        'core.role.read',
        'core.role.create',
        'core.role.update',
        'core.role.archive',
        'core.role.permission.assign',
        'core.role.data-policy.read',
        'core.role.data-policy.manage',
        'core.permission.read',
        'core.module.read',
        'core.module.configure',
        'core.audit.read',
    ];

    /** @var list<string> */
    public const PLATFORM = [
        'platform.module.read',
        'platform.module.create',
        'platform.module.install',
        'platform.module.update',
        'platform.module.uninstall',
        'platform.module.disable',
        'platform.module.sync',
        'platform.tenant.read',
        'platform.tenant.create',
        'platform.tenant.update',
        'platform.tenant.lifecycle',
        'platform.tenant.provision-owner',
        'platform.plan.read',
        'platform.plan.manage',
        'platform.tenant.license.manage',
        'platform.operator.read',
        'platform.operator.create',
        'platform.operator.update',
        'platform.operator.lifecycle',
        'platform.operator.role.assign',
        'platform.role.read',
        'platform.role.create',
        'platform.role.update',
        'platform.role.archive',
        'platform.role.permission.assign',
        'platform.permission.read',
        'platform.permission.api.manage',
        'platform.audit.read',
        'platform.upgrade.read',
        'platform.ops.read',
        'platform.ops.backup.manage',
        'platform.ops.restore.manage',
        'platform.ops.maintenance.manage',
        'platform.ops.module.manage',
        'platform.ops.upgrade.manage',
        'platform.ops.logs.read',
    ];

    private function __construct() {}
}
