<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\contract\payment\CallbackParserInterface;
use app\common\dto\payment\CallbackRequest;
use app\common\dto\payment\PaymentEvent;
use app\common\infrastructure\payment\PaymentCrypto;

final class AlipayCallbackParser implements CallbackParserInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function parse(CallbackRequest $request): PaymentEvent
    {
        $this->assertConfig();
        $params = $request->params();
        $signature = base64_decode((string) ($params['sign'] ?? ''), true);
        if (strtoupper((string) ($params['sign_type'] ?? '')) !== 'RSA2' || $signature === false) {
            throw new \RuntimeException('支付宝回调签名参数无效');
        }
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '' && !is_array($value)) {
                $pairs[] = $key . '=' . $value;
            }
        }
        if (openssl_verify(
            implode('&', $pairs),
            $signature,
            PaymentCrypto::publicKey((string) $this->config['ali_pay_public_key']),
            OPENSSL_ALGO_SHA256,
        ) !== 1) {
            throw new \RuntimeException('支付宝回调 RSA2 验签失败');
        }
        $appId = trim((string) ($params['app_id'] ?? ''));
        $sellerId = trim((string) ($params['seller_id'] ?? ''));
        if (!hash_equals(trim((string) $this->config['ali_pay_app_id']), $appId)) {
            throw new \RuntimeException('支付宝回调应用身份不匹配');
        }
        $expectedSeller = trim((string) ($this->config['ali_pay_seller_id'] ?? ''));
        if ($expectedSeller !== '' && !hash_equals($expectedSeller, $sellerId)) {
            throw new \RuntimeException('支付宝回调商户身份不匹配');
        }
        if ($sellerId === '') {
            throw new \RuntimeException('支付宝回调缺少商户身份');
        }
        return new PaymentEvent(
            'alipay',
            (string) ($params['out_trade_no'] ?? ''),
            (string) ($params['trade_no'] ?? ''),
            PaymentCrypto::decimalToCent((string) ($params['total_amount'] ?? '')),
            'CNY',
            match (strtoupper((string) ($params['trade_status'] ?? ''))) {
                'TRADE_SUCCESS', 'TRADE_FINISHED' => 'success',
                'TRADE_CLOSED' => 'failed',
                default => 'pending',
            },
            $sellerId,
            $appId,
        );
    }

    private function assertConfig(): void
    {
        if ((int) ($this->config['ali_pay_status'] ?? 0) !== 1) {
            throw new \RuntimeException('支付宝支付未开启');
        }
        foreach (['ali_pay_app_id', 'ali_pay_public_key'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new \RuntimeException('支付宝回调配置不完整:' . $key);
            }
        }
    }
}
