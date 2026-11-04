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

final class ModuleProvider extends \think\Service implements ModuleProviderContract
{
    public function register(): void
    {
        \app\common\composition\ModuleConsoleCommands::register($this->app, $this->moduleKey(), [
            'refund:reconcile' => \PeanutAdmin\Modules\Payment\Console\RefundReconcile::class,
        ]);
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
