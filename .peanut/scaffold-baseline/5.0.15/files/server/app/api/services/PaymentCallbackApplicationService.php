<?php

declare(strict_types=1);

namespace app\api\services;

use app\common\dto\payment\CallbackRequest;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Payment\Contract\PaymentMethod;
use PeanutAdmin\Modules\Payment\Contract\RechargeCommands;

/**
 * 支付回调应用入口。保留绑定解析→验签→受限上下文→模块许可→原充值状态机的顺序。
 * 不处理 HTTP 响应，不更改退款或入账规则；拒绝和未知结果继续由既有合同传播。
 */
final readonly class PaymentCallbackApplicationService
{
    public function __construct(
        private RechargeCommands $recharges,
        private ExecutionContextStore $executionContexts,
        private ModuleExecutionBoundary $modules,
        private ExternalTenantResolutionService $externalTenants,
    ) {}

    public function wechat(CallbackRequest $request, string $binding, string $operationId): void
    {
        $this->handle($request, $binding, $operationId, ExternalProvider::WECHAT_PAYMENT, 'wechat', PaymentMethod::WECHAT);
    }

    public function alipay(CallbackRequest $request, string $binding, string $operationId): void
    {
        $this->handle($request, $binding, $operationId, ExternalProvider::ALIPAY_PAYMENT, 'alipay', PaymentMethod::ALIPAY);
    }

    private function handle(
        CallbackRequest $request,
        string $binding,
        string $operationId,
        string $provider,
        string $channel,
        int $paymentMethod,
    ): void {
        $resolution = $this->externalTenants->verifiedCallback(
            $provider,
            $binding,
            'payment.settle',
            $operationId,
            fn(array $config) => $this->recharges->parseCallback($channel, $config, $request),
        );
        $event = $resolution->verifiedValue;
        $this->executionContexts->run(
            new SystemExecutionContext($resolution->context),
            function () use ($resolution, $event, $paymentMethod): void {
                $this->modules->assertExternalCallback('peanut.payment');
                if ($event->status() === 'success') {
                    $this->recharges->settleVerifiedCallback($resolution->binding->id, $event, $paymentMethod);
                }
            },
        );
    }
}
