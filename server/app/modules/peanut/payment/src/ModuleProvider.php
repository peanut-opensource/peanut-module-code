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

final class ModuleProvider implements ModuleProviderContract
{
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
