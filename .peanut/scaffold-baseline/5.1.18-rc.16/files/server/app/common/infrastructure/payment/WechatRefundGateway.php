<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\contract\payment\PaymentTransportInterface;
use app\common\contract\payment\RefundGatewayInterface;
use app\common\dto\payment\TransportResponse;
use app\common\infrastructure\payment\PaymentCrypto;

final class WechatRefundGateway implements RefundGatewayInterface
{
    private const HOST = 'https://api.mch.weixin.qq.com';
    private const REFUND_PATH = '/v3/refund/domestic/refunds';

    public function __construct(
        private array $config,
        private PaymentTransportInterface $transport,
    ) {}

    public function refund(array $order, string $refundSn, int $refundAmountCents): array
    {
        $this->assertConfig();
        $transactionId = trim((string) ($order['transaction_id'] ?? ''));
        $orderAmountCents = PaymentCrypto::decimalToCent((string) ($order['order_amount'] ?? ''));
        if ($refundSn === '' || $transactionId === '' || $refundAmountCents <= 0
            || $refundAmountCents > $orderAmountCents) {
            throw new \RuntimeException('微信退款订单参数无效');
        }
        $body = json_encode([
            'transaction_id' => $transactionId,
            'out_refund_no' => $refundSn,
            'reason' => '充值订单退款',
            'amount' => [
                'refund' => $refundAmountCents,
                'total' => $orderAmountCents,
                'currency' => 'CNY',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $authorization = $this->authorization('POST', self::REFUND_PATH, $body);

        try {
            $response = $this->transport->request(
                'POST',
                self::HOST . self::REFUND_PATH,
                [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: ' . $authorization,
                ],
                $body,
            );
            $this->verifyResponse($response);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                $exception->getMessage() !== '' ? $exception->getMessage() : '微信退款结果未知',
                self::ERROR_RESULT_UNKNOWN,
                $exception,
            );
        }

        try {
            $data = $this->decode($response, '微信退款响应格式异常');
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                $exception->getMessage(),
                self::ERROR_RESULT_UNKNOWN,
                $exception,
            );
        }
        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            $message = (string) ($data['message'] ?? $data['code'] ?? '退款请求失败');
            $code = $response->statusCode();
            throw new \RuntimeException(
                '微信支付:' . $message,
                $code >= 500 || in_array($code, [408, 429], true)
                    ? self::ERROR_RESULT_UNKNOWN
                    : 0,
            );
        }
        $channelStatus = strtoupper((string) ($data['status'] ?? ''));
        if (in_array($channelStatus, ['CLOSED', 'ABNORMAL'], true)) {
            throw new \RuntimeException('微信支付:退款请求失败（' . $channelStatus . '）');
        }

        return [
            'status' => self::STATUS_PENDING,
            'transaction_id' => (string) ($data['refund_id'] ?? ''),
            'receipt' => $this->safeReceipt($data),
        ];
    }

    public function query(array $order, string $refundSn): array
    {
        $this->assertConfig();
        if ($refundSn === '') {
            throw new \RuntimeException('退款流水号缺失');
        }
        $path = self::REFUND_PATH . '/' . rawurlencode($refundSn);
        $response = $this->transport->request(
            'GET',
            self::HOST . $path,
            [
                'Accept: application/json',
                'Authorization: ' . $this->authorization('GET', $path, ''),
            ],
        );
        $this->verifyResponse($response);
        $data = $this->decode($response, '微信退款查询响应格式异常');
        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw new \RuntimeException(
                '微信支付:' . (string) ($data['message'] ?? $data['code'] ?? '退款查询失败'),
            );
        }
        $status = match (strtoupper((string) ($data['status'] ?? ''))) {
            'SUCCESS' => self::STATUS_SUCCESS,
            'CLOSED', 'ABNORMAL' => self::STATUS_FAILED,
            default => self::STATUS_PENDING,
        };
        $transactionId = (string) ($data['refund_id'] ?? '');
        if ($status === self::STATUS_SUCCESS && trim($transactionId) === '') {
            throw new \RuntimeException('微信退款成功响应缺少退款流水号');
        }
        return [
            'status' => $status,
            'transaction_id' => $transactionId,
            'receipt' => $this->safeReceipt($data),
        ];
    }

    private function assertConfig(): void
    {
        if ((int) ($this->config['wx_pay_status'] ?? 0) !== 1) {
            throw new \RuntimeException('微信支付未开启');
        }
        foreach ([
            'wx_pay_mch_id', 'wx_pay_cert_path', 'wx_pay_cert_key_path',
            'wx_pay_platform_cert_path',
        ] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new \RuntimeException('微信支付配置不完整:' . $key);
            }
        }
    }

    private function authorization(string $method, string $path, string $body): string
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = strtoupper($method) . "\n{$path}\n{$timestamp}\n{$nonce}\n{$body}\n";
        return 'WECHATPAY2-SHA256-RSA2048 '
            . 'mchid="' . trim((string) $this->config['wx_pay_mch_id']) . '",'
            . 'nonce_str="' . $nonce . '",'
            . 'signature="' . PaymentCrypto::sign(
                $message,
                PaymentCrypto::privateKey((string) $this->config['wx_pay_cert_key_path']),
            ) . '",'
            . 'timestamp="' . $timestamp . '",'
            . 'serial_no="' . PaymentCrypto::certificateSerial(
                (string) $this->config['wx_pay_cert_path'],
            ) . '"';
    }

    private function verifyResponse(TransportResponse $response): void
    {
        PaymentCrypto::verifyWechatResponse(
            $response,
            (string) $this->config['wx_pay_platform_cert_path'],
        );
    }

    private function decode(TransportResponse $response, string $message): array
    {
        $data = json_decode($response->body(), true);
        if (!is_array($data)) {
            throw new \RuntimeException($message);
        }
        return $data;
    }

    private function safeReceipt(array $data): array
    {
        $receipt = array_intersect_key($data, array_flip([
            'code', 'message', 'refund_id', 'out_refund_no', 'status',
            'create_time', 'success_time',
        ]));
        return array_map(
            static fn(mixed $value): string => mb_substr((string) $value, 0, 500),
            $receipt,
        );
    }
}
