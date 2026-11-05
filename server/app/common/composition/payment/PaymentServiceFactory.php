<?php

declare(strict_types=1);

namespace app\common\composition\payment;

use app\common\contract\payment\PaymentChannelGateway;
use app\common\exception\BusinessException;
use app\common\infrastructure\payment\YansongdaGateway;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;

/** Resolve payment credentials only from a verified binding or a recorded payment attempt. */
final readonly class PaymentServiceFactory
{
    public function __construct(private ExternalTenantResolutionService $externalTenants) {}

    public function forHistory(int $tenantId, string $channel, int $bindingId, string $identityHash): PaymentChannelGateway
    {
        $provider = match ($channel) {
            'wechat' => ExternalProvider::WECHAT_PAYMENT,
            'alipay' => ExternalProvider::ALIPAY_PAYMENT,
        };
        $binding = $this->externalTenants->paymentBindingForHistory(
            $tenantId,
            $provider,
            $bindingId,
        );
        if (!hash_equals($binding->identityHash, $identityHash)) {
            throw BusinessException::conflict('PAYMENT_ORIGIN_UNVERIFIED', '原收款商户待核对');
        }

        return $this->forBinding($channel, $binding->config, $identityHash);
    }

    public function forBinding(string $channel, array $config, string $identityHash): PaymentChannelGateway
    {
        $app = trim((string) ($config[$channel === 'wechat' ? 'wx_pay_appid' : 'ali_pay_app_id'] ?? ''));
        $merchant = trim((string) ($config[$channel === 'wechat' ? 'wx_pay_mch_id' : 'ali_pay_seller_id'] ?? ''));
        if ($app === '' || $merchant === ''
            || !hash_equals($identityHash, hash('sha256', strtolower($app . ':' . $merchant)))) {
            throw BusinessException::conflict('PAYMENT_ORIGIN_UNVERIFIED', '原收款商户待核对');
        }

        return $this->forConfig($channel, $config);
    }

    public function forConfig(string $channel, array $config): PaymentChannelGateway
    {
        return new YansongdaGateway($channel, $config);
    }
}
