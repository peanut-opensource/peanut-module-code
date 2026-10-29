<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\contract\payment\PaymentTransportInterface;
use app\common\contract\payment\RefundGatewayInterface;
use app\common\dto\payment\TransportResponse;
use app\common\infrastructure\payment\PaymentCrypto;

final class AlipayRefundGateway implements RefundGatewayInterface
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    public function __construct(
        private array $config,
        private PaymentTransportInterface $transport,
    ) {}

    public function refund(array $order, string $refundSn, int $refundAmountCents): array
    {
        $this->assertConfig();
        if ($refundSn === '' || $refundAmountCents <= 0) {
            throw new \RuntimeException('支付宝退款订单参数无效');
        }
        $responseKey = 'alipay_trade_refund_response';
        $params = $this->signedParams('alipay.trade.refund', [
            'out_trade_no' => (string) ($order['sn'] ?? ''),
            'refund_amount' => number_format($refundAmountCents / 100, 2, '.', ''),
            'out_request_no' => $refundSn,
        ]);
        try {
            $response = $this->request($params);
            $data = $this->verifiedResponse($response, $responseKey);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                $exception->getMessage() !== '' ? $exception->getMessage() : '支付宝退款结果未知',
                self::ERROR_RESULT_UNKNOWN,
                $exception,
            );
        }
        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw new \RuntimeException(
                '支付宝退款网络请求失败（HTTP ' . $response->statusCode() . '）',
                $response->statusCode() >= 500 || in_array($response->statusCode(), [408, 429], true)
                    ? self::ERROR_RESULT_UNKNOWN
                    : 0,
            );
        }
        $code = (string) ($data['code'] ?? '');
        $statusMessage = (string) ($data['msg'] ?? '');
        if ($code !== '10000' || $statusMessage !== 'Success') {
            throw new \RuntimeException(
                '支付宝:' . (string) ($data['sub_msg'] ?? $statusMessage ?: '退款请求失败'),
            );
        }
        $status = (string) ($data['fund_change'] ?? $data['fundChange'] ?? '') === 'Y'
            ? self::STATUS_SUCCESS
            : self::STATUS_PENDING;
        $transactionId = (string) ($data['trade_no'] ?? $data['tradeNo'] ?? '');
        if ($status === self::STATUS_SUCCESS && trim($transactionId) === '') {
            throw new \RuntimeException('支付宝退款成功响应缺少交易流水号', self::ERROR_RESULT_UNKNOWN);
        }
        return [
            'status' => $status,
            'transaction_id' => $transactionId,
            'receipt' => $this->safeReceipt($data),
        ];
    }

    public function query(array $order, string $refundSn): array
    {
        $this->assertConfig();
        if ($refundSn === '') {
            throw new \RuntimeException('退款流水号缺失');
        }
        $responseKey = 'alipay_trade_fastpay_refund_query_response';
        $response = $this->request($this->signedParams('alipay.trade.fastpay.refund.query', [
            'out_trade_no' => (string) ($order['sn'] ?? ''),
            'out_request_no' => $refundSn,
        ]));
        $data = $this->verifiedResponse($response, $responseKey);
        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw new \RuntimeException(
                '支付宝退款查询网络请求失败（HTTP ' . $response->statusCode() . '）',
            );
        }
        if ((string) ($data['code'] ?? '') !== '10000') {
            return [
                'status' => self::STATUS_PENDING,
                'transaction_id' => '',
                'receipt' => $this->safeReceipt($data),
            ];
        }
        $status = match (strtoupper((string) ($data['refund_status'] ?? ''))) {
            'REFUND_SUCCESS' => self::STATUS_SUCCESS,
            'REFUND_FAIL', 'REFUND_FAILED', 'FAILED', 'CLOSED' => self::STATUS_FAILED,
            default => self::STATUS_PENDING,
        };
        $transactionId = (string) ($data['trade_no'] ?? '');
        if ($status === self::STATUS_SUCCESS && trim($transactionId) === '') {
            throw new \RuntimeException('支付宝退款成功响应缺少交易流水号');
        }
        return [
            'status' => $status,
            'transaction_id' => $transactionId,
            'receipt' => $this->safeReceipt($data),
        ];
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

    private function signedParams(string $method, array $business): array
    {
        $params = [
            'app_id' => trim((string) $this->config['ali_pay_app_id']),
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode(
                $business,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ];
        ksort($params);
        $params['sign'] = PaymentCrypto::sign(
            $this->queryStringForSign($params),
            PaymentCrypto::privateKey((string) $this->config['ali_pay_private_key']),
        );
        return $params;
    }

    private function request(array $params): TransportResponse
    {
        return $this->transport->request(
            'POST',
            self::GATEWAY,
            ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        );
    }

    private function verifiedResponse(TransportResponse $response, string $responseKey): array
    {
        $decoded = json_decode($response->body(), true);
        $data = is_array($decoded) ? ($decoded[$responseKey] ?? null) : null;
        if (!is_array($decoded) || !is_array($data)) {
            throw new \RuntimeException('支付宝响应格式异常');
        }
        PaymentCrypto::verifyAlipayResponse(
            $response->body(),
            $decoded,
            (string) $this->config['ali_pay_public_key'],
            $responseKey,
        );
        return $data;
    }

    private function queryStringForSign(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $pairs[] = $key . '=' . $value;
            }
        }
        return implode('&', $pairs);
    }

    private function safeReceipt(array $data): array
    {
        $receipt = array_intersect_key($data, array_flip([
            'code', 'msg', 'sub_code', 'sub_msg', 'trade_no', 'out_trade_no',
            'out_request_no', 'fund_change', 'refund_fee', 'refund_status',
        ]));
        return array_map(
            static fn(mixed $value): string => mb_substr((string) $value, 0, 500),
            $receipt,
        );
    }
}
