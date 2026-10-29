<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment;

use PeanutAdmin\Modules\Payment\Service\RechargeApplicationService;
use PeanutAdmin\Modules\Payment\Contract\PaymentChannelGrantCommands;
use PeanutAdmin\Modules\Payment\Contract\RechargeCommands;
use PeanutAdmin\Modules\Payment\Contract\RechargeQueries;
use PeanutAdmin\Modules\Payment\Contract\RefundReconciliationCommands;
use PeanutAdmin\Modules\Payment\Infrastructure\ThinkPhpPaymentChannelGrantCommands;
use PeanutAdmin\Modules\Payment\Infrastructure\ThinkPhpRefundReconciliationCommands;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Kernel\Module\TenantModuleEnableHook;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Task\Contract\TaskBootstrapCommands;
use PeanutAdmin\Modules\Payment\Service\PaymentBootstrapDefaults;
use think\App;

final class ModuleProvider implements ModuleProviderContract, TenantModuleEnableHook
{
    public function __construct(private readonly App $app) {}

    public function enable(int $tenantId, array $config): void
    {
        $this->app->make(TaskBootstrapCommands::class)->seedDefaults(new TenantSystemContext(
            $tenantId,
            'module.payment',
            'payment.provision-tenant-defaults',
            'module-enable:peanut.payment:' . $tenantId,
        ), PaymentBootstrapDefaults::tasks());
    }

    public function disable(int $tenantId): void
    {
        // Disabling preserves the task; execution still checks Payment availability.
    }

    public function moduleKey(): string
    {
        return 'peanut.payment';
    }

    public function bindings(): array
    {
        return [
            PaymentChannelGrantCommands::class => ThinkPhpPaymentChannelGrantCommands::class,
            RechargeCommands::class => RechargeApplicationService::class,
            RechargeQueries::class => RechargeApplicationService::class,
            RefundReconciliationCommands::class => ThinkPhpRefundReconciliationCommands::class,
        ];
    }
}
