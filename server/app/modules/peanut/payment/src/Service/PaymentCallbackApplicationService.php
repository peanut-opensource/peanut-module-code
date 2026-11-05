<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

use PeanutAdmin\Modules\Payment\Infrastructure\Channel\CallbackRequest;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Payment\Contract\PaymentMethod;

/**
 * 支付回调应用入口。先解析绑定并验签，再在其租户上下文收尾已发生的交易。
 * 不处理 HTTP 响应，不更改退款或入账规则；拒绝和未知结果继续由既有合同传播。
 */
final readonly class PaymentCallbackApplicationService
{
    public function __construct(
        private RechargeApplicationService $recharges,
        private ExecutionContextStore $executionContexts,
        private ModuleGuard $modules,
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
                // An authenticated result for an existing attempt survives the tenant's sales entitlement.
                $this->modules->assertDeployment('peanut.payment');
                if ($event->status() === 'success') {
                    $this->recharges->settleVerifiedCallback($resolution->binding->id, $event, $paymentMethod);
                }
            },
        );
    }
}
