<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\contract\payment\PaymentTransportInterface;
use app\common\contract\payment\PrepayGatewayInterface;
use app\common\dto\payment\PrepayRequest;
use app\common\dto\payment\PrepayResult;
use app\common\infrastructure\payment\PaymentCrypto;

final class WechatPayGateway implements PrepayGatewayInterface
{
    private array $config;
    private PaymentTransportInterface $transport;

    public function __construct(array $config, PaymentTransportInterface $transport)
    {
        $this->config = $config;
        $this->transport = $transport;
    }

    public function prepay(PrepayRequest $request): PrepayResult
    {
        $this->assertConfig();
        [$scene, $endpoint] = match ($request->terminal()) {
            PrepayRequest::TERMINAL_MINI_PROGRAM,
            PrepayRequest::TERMINAL_OFFICIAL_ACCOUNT => ['JSAPI', '/v3/pay/transactions/jsapi'],
            PrepayRequest::TERMINAL_H5 => ['MWEB', '/v3/pay/transactions/h5'],
            PrepayRequest::TERMINAL_PC => ['NATIVE', '/v3/pay/transactions/native'],
            PrepayRequest::TERMINAL_IOS,
            PrepayRequest::TERMINAL_ANDROID => ['APP', '/v3/pay/transactions/app'],
            default => throw new \RuntimeException('微信支付终端无效'),
        };
        if ($scene === 'JSAPI' && $request->openid() === '') {
            throw new \RuntimeException('微信 JSAPI 支付缺少 openid');
        }
        if ($scene === 'MWEB' && filter_var($request->clientIp(), FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('微信 H5 支付缺少有效客户端 IP');
        }

        $payload = [
            'appid' => trim((string) $this->config['wx_pay_appid']),
            'mchid' => trim((string) $this->config['wx_pay_mch_id']),
            'description' => $request->description(),
            'out_trade_no' => $request->orderSn(),
            'notify_url' => $request->notifyUrl(),
            'amount' => ['total' => $request->amount(), 'currency' => $request->currency()],
        ];
        if ($scene === 'JSAPI') {
            $payload['payer'] = ['openid' => $request->openid()];
        } elseif ($scene === 'MWEB') {
            $payload['scene_info'] = [
                'payer_client_ip' => $request->clientIp(),
                'h5_info' => ['type' => 'Wap'],
            ];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('微信预支付请求生成失败');
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $privateKey = PaymentCrypto::privateKey((string) $this->config['wx_pay_cert_key_path']);
        $serial = $this->merchantCertificateSerial();
        $message = "POST\n{$endpoint}\n{$timestamp}\n{$nonce}\n{$body}\n";
        $authorization = 'WECHATPAY2-SHA256-RSA2048 '
            . 'mchid="' . trim((string) $this->config['wx_pay_mch_id']) . '",'
            . 'nonce_str="' . $nonce . '",'
            . 'signature="' . PaymentCrypto::sign($message, $privateKey) . '",'
            . 'timestamp="' . $timestamp . '",'
            . 'serial_no="' . $serial . '"';
        $response = $this->transport->request(
            'POST',
            'https://api.mch.weixin.qq.com' . $endpoint,
            ['Accept: application/json', 'Content-Type: application/json', 'Authorization: ' . $authorization],
            $body,
        );
        PaymentCrypto::verifyWechatResponse(
            $response,
            (string) $this->config['wx_pay_platform_cert_path'],
        );
        $decoded = json_decode($response->body(), true);
        if ($response->statusCode() < 200 || $response->statusCode() >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['code'] ?? '') : '';
            throw new \RuntimeException('微信预支付失败' . ($message !== '' ? ':' . $message : ''));
        }
        return new PrepayResult('wechat', $scene, $this->clientPayload($scene, $decoded, $privateKey));
    }

    private function assertConfig(): void
    {
        if ((int) ($this->config['wx_pay_status'] ?? 0) !== 1) {
            throw new \RuntimeException('微信支付未开启');
        }
        foreach ([
            'wx_pay_appid', 'wx_pay_mch_id', 'wx_pay_cert_path',
            'wx_pay_cert_key_path', 'wx_pay_platform_cert_path',
        ] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new \RuntimeException('微信支付配置不完整:' . $key);
            }
        }
    }

    private function merchantCertificateSerial(): string
    {
        return PaymentCrypto::certificateSerial((string) $this->config['wx_pay_cert_path']);
    }

    private function clientPayload(string $scene, array $response, $privateKey): array
    {
        if ($scene === 'NATIVE') {
            $value = trim((string) ($response['code_url'] ?? ''));
            if ($value === '') {
                throw new \RuntimeException('微信预支付响应缺少 code_url');
            }
            return ['code_url' => $value];
        }
        if ($scene === 'MWEB') {
            $value = trim((string) ($response['h5_url'] ?? ''));
            if ($value === '') {
                throw new \RuntimeException('微信预支付响应缺少 h5_url');
            }
            return ['h5_url' => $value];
        }
        $prepayId = trim((string) ($response['prepay_id'] ?? ''));
        if ($prepayId === '') {
            throw new \RuntimeException('微信预支付响应缺少 prepay_id');
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $appId = trim((string) $this->config['wx_pay_appid']);
        if ($scene === 'JSAPI') {
            $package = 'prepay_id=' . $prepayId;
            return [
                'appId' => $appId,
                'timeStamp' => $timestamp,
                'nonceStr' => $nonce,
                'package' => $package,
                'signType' => 'RSA',
                'paySign' => PaymentCrypto::sign("{$appId}\n{$timestamp}\n{$nonce}\n{$package}\n", $privateKey),
            ];
        }
        $package = 'Sign=WXPay';
        return [
            'appid' => $appId,
            'partnerid' => trim((string) $this->config['wx_pay_mch_id']),
            'prepayid' => $prepayId,
            'package' => $package,
            'noncestr' => $nonce,
            'timestamp' => $timestamp,
            'sign' => PaymentCrypto::sign(
                "{$appId}\n" . trim((string) $this->config['wx_pay_mch_id']) . "\n{$prepayId}\n{$package}\n{$nonce}\n{$timestamp}\n",
                $privateKey,
            ),
        ];
    }
}
