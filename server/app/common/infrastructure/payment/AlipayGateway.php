<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\contract\payment\PrepayGatewayInterface;
use app\common\dto\payment\PrepayRequest;
use app\common\dto\payment\PrepayResult;
use app\common\infrastructure\payment\PaymentCrypto;

final class AlipayGateway implements PrepayGatewayInterface
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function prepay(PrepayRequest $request): PrepayResult
    {
        $this->assertConfig();
        [$scene, $method, $productCode] = match ($request->terminal()) {
            PrepayRequest::TERMINAL_MINI_PROGRAM => throw new \RuntimeException('支付宝不支持小程序充值'),
            PrepayRequest::TERMINAL_OFFICIAL_ACCOUNT,
            PrepayRequest::TERMINAL_H5 => ['WAP', 'alipay.trade.wap.pay', 'QUICK_WAP_WAY'],
            PrepayRequest::TERMINAL_PC => ['PAGE', 'alipay.trade.page.pay', 'FAST_INSTANT_TRADE_PAY'],
            PrepayRequest::TERMINAL_IOS,
            PrepayRequest::TERMINAL_ANDROID => ['APP', 'alipay.trade.app.pay', 'QUICK_MSECURITY_PAY'],
            default => throw new \RuntimeException('支付宝支付终端无效'),
        };
        $params = [
            'app_id' => trim((string) $this->config['ali_pay_app_id']),
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $request->notifyUrl(),
            'biz_content' => json_encode([
                'out_trade_no' => $request->orderSn(),
                'total_amount' => number_format($request->amount() / 100, 2, '.', ''),
                'subject' => $request->description(),
                'product_code' => $productCode,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($params['biz_content'] === false) {
            throw new \RuntimeException('支付宝预支付请求生成失败');
        }
        ksort($params);
        $signContent = $this->signContent($params);
        $params['sign'] = PaymentCrypto::sign(
            $signContent,
            PaymentCrypto::privateKey((string) $this->config['ali_pay_private_key']),
        );
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return new PrepayResult('alipay', $scene, $scene === 'APP'
            ? ['order_string' => $query]
            : ['gateway_url' => self::GATEWAY . '?' . $query]);
    }

    private function assertConfig(): void
    {
        if ((int) ($this->config['ali_pay_status'] ?? 0) !== 1) {
            throw new \RuntimeException('支付宝支付未开启');
        }
        foreach (['ali_pay_app_id', 'ali_pay_private_key', 'ali_pay_public_key'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new \RuntimeException('支付宝支付配置不完整:' . $key);
            }
        }
    }

    private function signContent(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ((string) $value !== '') {
                $pairs[] = $key . '=' . $value;
            }
        }
        return implode('&', $pairs);
    }
}
