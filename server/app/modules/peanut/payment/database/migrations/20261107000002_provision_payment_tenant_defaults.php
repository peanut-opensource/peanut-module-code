<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformWorkspaceQueryService;
use PeanutAdmin\Modules\Payment\Service\PaymentBootstrapDefaults;
use PeanutAdmin\Modules\Task\Contract\TaskBootstrapCommands;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Add missing tenant schedules without changing existing configuration or module entitlements. */
final class ProvisionPaymentTenantDefaults extends Migrator
{
    public function up(): void
    {
        $tenants = app(PlatformWorkspaceQueryService::class);
        $tasks = app(TaskBootstrapCommands::class);
        for ($page = 1; ; $page++) {
            $result = $tenants->tenants(new PageRequest($page, 100));
            foreach ($result['items'] as $tenant) {
                if ($tenant['status'] === 'closed') {
                    continue;
                }
                $system = new TenantSystemContext(
                    (int) $tenant['id'],
                    'module.payment',
                    'payment.provision-tenant-defaults',
                    'migration:20261107000002:' . $tenant['id'],
                );
                $tasks->seedDefaults($system, PaymentBootstrapDefaults::tasks());
            }
            if ($page * 100 >= $result['total']) {
                break;
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Tenant payment schedules and current configuration are retained.');
    }
}
