<?php

declare(strict_types=1);

use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformWorkspaceQueryService;
use PeanutAdmin\Modules\Notification\Service\NotificationBootstrapService;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Existing tenants need the same missing defaults as tenants created after Module installation. */
final class ProvisionNotificationTenantDefaults extends Migrator
{
    public function up(): void
    {
        $tenants = app(PlatformWorkspaceQueryService::class);
        $defaults = app(NotificationBootstrapService::class);
        for ($page = 1; ; $page++) {
            $result = $tenants->tenants(new PageRequest($page, 100));
            foreach ($result['items'] as $tenant) {
                if ($tenant['status'] === 'closed') {
                    continue;
                }
                $context = new SystemExecutionContext(new TenantSystemContext(
                    (int) $tenant['id'],
                    'module.notification',
                    'notification.provision-tenant-defaults',
                    'migration:20261107000001:' . $tenant['id'],
                ));
                $defaults->provisionTenantDefaults($context);
            }
            if ($page * 100 >= $result['total']) {
                break;
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Tenant notification defaults and current configuration are retained.');
    }
}
