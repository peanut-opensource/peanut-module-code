<?php

declare(strict_types=1);

namespace app\common\composition\payment;

use app\common\infrastructure\payment\AlipayCallbackParser;
use app\common\infrastructure\payment\WechatCallbackParser;
use app\common\contract\payment\CallbackParserInterface;
use app\common\contract\payment\PaymentTransportInterface;
use app\common\contract\payment\PrepayGatewayInterface;
use app\common\contract\payment\RefundGatewayInterface;
use app\common\infrastructure\payment\AlipayGateway;
use app\common\infrastructure\payment\AlipayRefundGateway;
use app\common\infrastructure\payment\WechatPayGateway;
use app\common\infrastructure\payment\WechatRefundGateway;
use app\common\infrastructure\payment\CurlPaymentTransport;
use app\common\contract\http\OutboundHttpTransport;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;

/** Peanut 自有支付边界工厂，不承载参考系统的路由或参数兼容。 */
final class PaymentServiceFactory
{
    private array $config;
    private PaymentTransportInterface $transport;

    public function __construct(
        private readonly ExternalTenantResolutionService $externalTenants,
        private readonly OutboundHttpTransport $httpTransport,
        array $config = [],
        ?PaymentTransportInterface $transport = null,
    ) {
        $this->config = $config;
        $this->transport = $transport ?? new CurlPaymentTransport($httpTransport);
    }

    public function forTenant(
        object $context,
        string $channel,
        ?PaymentTransportInterface $transport = null,
    ): self {
        $provider = match (strtolower(trim($channel))) {
            'wechat' => ExternalProvider::WECHAT_PAYMENT,
            'alipay' => ExternalProvider::ALIPAY_PAYMENT,
            default => throw new \RuntimeException('支付渠道不受支持'),
        };
        $binding = $this->externalTenants->bindingForTenant(ExternalTenantContext::tenantId($context), $provider);
        return $this->forConfig($binding->config, $transport);
    }

    public function forConfig(array $config, ?PaymentTransportInterface $transport = null): self
    {
        return new self($this->externalTenants, $this->httpTransport, $config, $transport);
    }

    public function prepay(string $channel): PrepayGatewayInterface
    {
        return match (strtolower(trim($channel))) {
            'wechat' => new WechatPayGateway($this->config, $this->transport),
            'alipay' => new AlipayGateway($this->config),
            default => throw new \RuntimeException('支付渠道不受支持'),
        };
    }

    public function callback(string $channel): CallbackParserInterface
    {
        return match (strtolower(trim($channel))) {
            'wechat' => new WechatCallbackParser($this->config),
            'alipay' => new AlipayCallbackParser($this->config),
            default => throw new \RuntimeException('支付渠道不受支持'),
        };
    }

    public function refund(string $channel): RefundGatewayInterface
    {
        return match (strtolower(trim($channel))) {
            'wechat' => new WechatRefundGateway($this->config, $this->transport),
            'alipay' => new AlipayRefundGateway($this->config, $this->transport),
            default => throw new \RuntimeException('支付渠道不受支持'),
        };
    }
}
