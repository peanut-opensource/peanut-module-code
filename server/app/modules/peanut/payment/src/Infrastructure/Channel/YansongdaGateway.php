<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Infrastructure\Channel;

use app\common\value\Money;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Yansongda\Artful\Artful;
use Yansongda\Artful\Rocket;
use Yansongda\Artful\Contract\ShortcutInterface;
use Yansongda\Pay\Pay;
use Yansongda\Pay\Plugin\Alipay\V2\StartPlugin;
use Yansongda\Pay\Plugin\Wechat\ResponsePlugin as WechatResponsePlugin;
use Yansongda\Pay\Shortcut\Alipay;
use Yansongda\Pay\Shortcut\Wechat\CloseShortcut as WechatCloseShortcut;
use Yansongda\Pay\Shortcut\Wechat\QueryShortcut as WechatQueryShortcut;
use Yansongda\Supports\Collection;

final readonly class YansongdaGateway implements PaymentChannelGateway
{
    public function __construct(private string $channel, private array $config) {}

    public function prepay(PrepayRequest $request): PrepayResult
    {
        return $this->withinMerchant(function (array $params) use ($request): PrepayResult {
            $params['out_trade_no'] = $request->orderSn();
            if ($this->channel === 'alipay') {
                [$scene, $shortcut] = match ($request->terminal()) {
                    2, 3 => ['WAP', new Alipay\H5Shortcut()],
                    4 => ['PAGE', new Alipay\WebShortcut()],
                    5, 6 => ['APP', new Alipay\AppShortcut()],
                    default => throw new \RuntimeException('支付宝不支持该充值终端'),
                };
                if ($request->expiresAt !== null) {
                    $params['time_expire'] = (new \DateTimeImmutable('@' . $request->expiresAt))
                        ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
                }
                $result = $this->alipay($shortcut, $params + [
                    '_method' => 'GET',
                    '_notify_url' => $request->notifyUrl(),
                    'total_amount' => Money::fromCents($request->amount()),
                    'subject' => $request->description(),
                ]);
                $value = $result instanceof ResponseInterface
                    ? ($scene === 'APP' ? (string) $result->getBody() : $result->getHeaderLine('Location'))
                    : '';
                if ($value === '') {
                    throw new \RuntimeException('支付宝预支付响应缺少拉起参数');
                }
                return new PrepayResult('alipay', $scene, [($scene === 'APP' ? 'order_string' : 'gateway_url') => $value]);
            }

            $params += [
                'description' => $request->description(),
                'notify_url' => $request->notifyUrl(),
                'amount' => ['total' => $request->amount(), 'currency' => $request->currency()],
            ];
            $gateway = Pay::wechat();
            [$scene, $result] = match ($request->terminal()) {
                1 => ['JSAPI', $gateway->mini($params + ['payer' => ['openid' => $request->openid()]])],
                2 => ['JSAPI', $gateway->mp($params + ['payer' => ['openid' => $request->openid()]])],
                3 => ['MWEB', $gateway->h5($params + ['scene_info' => [
                    'payer_client_ip' => $request->clientIp(), 'h5_info' => ['type' => 'Wap'],
                ]])],
                4 => ['NATIVE', $gateway->scan($params)],
                5, 6 => ['APP', $gateway->app($params)],
                default => throw new \RuntimeException('微信支付终端无效'),
            };
            $data = self::result($result);
            $field = match ($scene) {
                'NATIVE' => 'code_url',
                'MWEB' => 'h5_url',
                'JSAPI' => 'paySign',
                'APP' => 'sign',
            };
            if (empty($data[$field])) {
                throw new \RuntimeException('微信预支付响应缺少拉起参数');
            }
            return new PrepayResult('wechat', $scene, $data);
        });
    }

    public function parse(CallbackRequest $request): PaymentEvent
    {
        return $this->withinMerchant(function (array $params) use ($request): PaymentEvent {
            if ($this->channel === 'wechat') {
                $timestamp = $request->header('Wechatpay-Timestamp');
                if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
                    || $request->header('Wechatpay-Nonce') === ''
                    || !hash_equals(
                        PaymentCrypto::certificateSerial((string) $this->config['wx_pay_platform_cert_path']),
                        strtoupper($request->header('Wechatpay-Serial')),
                    )) {
                    throw new \RuntimeException('微信回调签名头无效或已过期');
                }
                $message = new ServerRequest('POST', 'https://payment.invalid', $request->headers(), $request->body());
                $data = self::result(Pay::wechat()->callback($message, $params));
                return $this->verifiedEvent($data['resource']['ciphertext'] ?? []);
            }

            $fields = $request->params();
            if (($fields['sign_type'] ?? '') !== 'RSA2') {
                throw new \RuntimeException('支付宝回调签名类型无效');
            }
            return $this->verifiedEvent(self::result(Pay::alipay()->callback($fields, $params)));
        });
    }

    /** @return array{status:string,event:?PaymentEvent} */
    public function queryPayment(string $number): array
    {
        return $this->withinMerchant(function (array $params) use ($number): array {
            $params['out_trade_no'] = $number;
            $data = $this->channel === 'wechat'
                ? $this->wechatPaymentQuery($params)
                : self::result($this->alipay(new Alipay\QueryShortcut(), $params));
            if ($data === null || ($this->channel === 'alipay' && ($data['sub_code'] ?? '') === 'ACQ.TRADE_NOT_EXIST')) {
                return ['status' => 'absent', 'event' => null];
            }
            if (($data['out_trade_no'] ?? '') !== $number
                || ($this->channel === 'alipay' && ($data['code'] ?? '') !== '10000')) {
                throw new \RuntimeException('查单结果与原订单不匹配');
            }
            if ($this->channel === 'wechat' && (($data['mchid'] ?? '') !== $this->config['wx_pay_mch_id']
                || ($data['appid'] ?? '') !== $this->config['wx_pay_appid'])) {
                throw new \RuntimeException('查单结果与原商户身份不匹配');
            }
            $state = (string) ($data[$this->channel === 'wechat' ? 'trade_state' : 'trade_status'] ?? '');
            if (in_array($state, ['SUCCESS', 'TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
                return ['status' => 'paid', 'event' => $this->verifiedEvent($data, true)];
            }
            $status = match ($state) {
                'CLOSED', 'REVOKED', 'TRADE_CLOSED' => 'closed',
                'NOTPAY', 'WAIT_BUYER_PAY' => 'unpaid',
                default => 'unknown',
            };
            return ['status' => $status, 'event' => null];
        });
    }

    /** Keep the SDK's signature check when classifying a signed order-not-found response. */
    private function wechatPaymentQuery(array $params): ?array
    {
        $params += ['_action' => 'native', '_return_rocket' => true];
        // The SDK's generic status plugin throws before the outer signature plugin runs.
        $plugins = array_values(array_filter(
            (new WechatQueryShortcut())->getPlugins($params),
            static fn (string $plugin): bool => $plugin !== WechatResponsePlugin::class,
        ));
        $result = Pay::wechat()->pay($plugins, $params);
        $response = $result instanceof Rocket ? $result->getDestinationOrigin() : null;
        if (!$response instanceof ResponseInterface) {
            throw new \RuntimeException('微信查单结果未确认');
        }
        $data = self::result($result->getDestination());
        if ($response->getStatusCode() === 404 && ($data['code'] ?? '') === 'ORDER_NOT_EXIST') {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('微信查单结果未确认');
        }
        return $data;
    }

    public function close(string $number): void
    {
        $this->withinMerchant(function (array $params) use ($number): void {
            $params['out_trade_no'] = $number;
            if ($this->channel === 'wechat') {
                // The SDK's close() convenience method discards the shortcut's response.
                $params += ['_action' => 'native', '_return_rocket' => true];
                $result = Pay::wechat()->pay((new WechatCloseShortcut())->getPlugins($params), $params);
                $response = $result instanceof Rocket ? $result->getDestinationOrigin() : null;
                if ($response instanceof ResponseInterface && $response->getStatusCode() === 204) {
                    return;
                }
                throw new \RuntimeException('微信关单结果未确认');
            }
            $data = self::result($this->alipay(new Alipay\CloseShortcut(), $params));
            if (($data['code'] ?? '') === '10000' && ($data['out_trade_no'] ?? '') === $number) {
                return;
            }
            throw new \RuntimeException('关单结果未确认');
        });
    }

    public function refund(array $order, string $refundSn, int $refundAmountCents): array
    {
        try {
            return $this->withinMerchant(function (array $params) use ($order, $refundSn, $refundAmountCents): array {
                $data = self::result($this->channel === 'wechat'
                    ? Pay::wechat()->refund($params + [
                        'transaction_id' => (string) $order['transaction_id'],
                        'out_refund_no' => $refundSn,
                        'amount' => ['refund' => $refundAmountCents, 'total' => Money::toCents((string) $order['order_amount']), 'currency' => 'CNY'],
                    ])
                    : $this->alipay(new Alipay\RefundShortcut(), $params + [
                        'out_trade_no' => (string) $order['sn'],
                        'out_request_no' => $refundSn,
                        'refund_amount' => Money::fromCents($refundAmountCents),
                    ]));
                return $this->refundResult($data, $refundSn, $order, $refundAmountCents, false);
            });
        } catch (\Throwable $exception) {
            throw new \RuntimeException('渠道退款结果待确认', self::ERROR_RESULT_UNKNOWN, $exception);
        }
    }

    public function query(array $order, string $refundSn): array
    {
        return $this->withinMerchant(function (array $params) use ($order, $refundSn): array {
            $data = self::result($this->channel === 'wechat'
                ? Pay::wechat()->query($params + ['_action' => 'refund', 'out_refund_no' => $refundSn])
                : $this->alipay(new Alipay\QueryShortcut(), $params + [
                    '_action' => 'refund', 'out_trade_no' => (string) $order['sn'], 'out_request_no' => $refundSn,
                ]));
            return $this->refundResult($data, $refundSn, $order, (int) $order['requested_refund_cents'], true);
        });
    }

    private function refundResult(array $data, string $refundSn, array $order, int $cents, bool $query): array
    {
        if ($this->channel === 'wechat') {
            $state = $data['status'] ?? '';
            $id = (string) ($data['refund_id'] ?? '');
            if (($data['out_refund_no'] ?? '') !== $refundSn || ($data['out_trade_no'] ?? '') !== $order['sn']
                || ($data['transaction_id'] ?? '') !== $order['transaction_id']
                || (int) ($data['amount']['refund'] ?? 0) !== $cents) {
                throw new \RuntimeException('微信退款结果与原单不匹配');
            }
        } else {
            $id = (string) ($data['trade_no'] ?? '');
            if (($data['code'] ?? '') !== '10000') {
                return ['status' => self::STATUS_PENDING, 'transaction_id' => '', 'receipt' => []];
            }
            if ($id !== $order['transaction_id'] || ($data['out_trade_no'] ?? '') !== $order['sn']
                || ($query && ($data['out_request_no'] ?? '') !== $refundSn)) {
                throw new \RuntimeException('支付宝退款结果与原单不匹配');
            }
            if (!$query) {
                // refund_fee is the cumulative total for the trade. The query can verify this refund alone.
                return ['status' => self::STATUS_PENDING, 'transaction_id' => '', 'receipt' => ['channel_status' => 'ACCEPTED']];
            }
            $state = $data['refund_status'] ?? '';
            if ($state === 'REFUND_SUCCESS' && PaymentCrypto::decimalToCent((string) ($data['refund_amount'] ?? '')) !== $cents) {
                throw new \RuntimeException('支付宝退款金额不匹配');
            }
        }
        $status = match ($state) {
            'SUCCESS', 'REFUND_SUCCESS' => self::STATUS_SUCCESS,
            'CLOSED', 'REFUND_FAIL', 'REFUND_FAILED' => self::STATUS_FAILED,
            default => self::STATUS_PENDING,
        };
        if ($status === self::STATUS_SUCCESS && $id === '') {
            throw new \RuntimeException('渠道退款结果缺少流水号');
        }
        return ['status' => $status, 'transaction_id' => $id, 'receipt' => ['channel_status' => $state]];
    }

    private function verifiedEvent(array $data, bool $query = false): PaymentEvent
    {
        $wechat = $this->channel === 'wechat';
        $app = (string) ($wechat ? ($data['appid'] ?? '') : ($data['app_id'] ?? ($query ? $this->config['ali_pay_app_id'] : '')));
        // The Alipay query is signed by the bound application; unlike callbacks,
        // its response may omit the application or seller fields.
        $merchant = (string) ($data[$wechat ? 'mchid' : 'seller_id']
            ?? (!$wechat && $query ? $this->config['ali_pay_seller_id'] : ''));
        if ($app !== ($this->config[$wechat ? 'wx_pay_appid' : 'ali_pay_app_id'] ?? '')
            || $merchant !== ($this->config[$wechat ? 'wx_pay_mch_id' : 'ali_pay_seller_id'] ?? '')) {
            throw new \RuntimeException('支付商户或应用身份不匹配');
        }
        $state = (string) ($data[$wechat ? 'trade_state' : 'trade_status'] ?? '');
        return new PaymentEvent(
            $this->channel,
            (string) ($data['out_trade_no'] ?? ''),
            (string) ($data[$wechat ? 'transaction_id' : 'trade_no'] ?? ''),
            $wechat ? (int) ($data['amount']['total'] ?? 0) : PaymentCrypto::decimalToCent((string) ($data['total_amount'] ?? '')),
            $wechat ? (string) ($data['amount']['currency'] ?? '') : 'CNY',
            in_array($state, ['SUCCESS', 'TRADE_SUCCESS', 'TRADE_FINISHED'], true) ? 'success' : 'pending',
            $merchant,
            $app,
        );
    }

    private function alipay(ShortcutInterface $shortcut, array $params): mixed
    {
        $plugins = array_map(
            static fn (string $plugin): string => $plugin === StartPlugin::class ? AlipayPublicKeyStart::class : $plugin,
            $shortcut->getPlugins($params),
        );
        return Pay::alipay()->pay($plugins, $params);
    }

    private function withinMerchant(callable $operation): mixed
    {
        // Artful's container is process-static. The blocking SDK call owns it until cleanup.
        // Refuse overlapping calls before either operation can replace the other's configuration.
        if (Artful::hasContainer()) {
            throw new \RuntimeException('支付 SDK 正在处理另一笔渠道请求');
        }
        $key = 'binding_' . hash('sha256', serialize($this->config));
        $config = $this->sdkConfiguration();
        try {
            Pay::config([$this->channel => [$key => $config], 'logger' => ['enable' => false]]);
            return $operation(['_config' => $key]);
        } catch (\Yansongda\Artful\Exception\Exception|\Yansongda\Pay\Exception\Exception|ClientExceptionInterface) {
            // The SDK can attach raw provider bodies or signing configuration to its exceptions.
            throw new \RuntimeException('支付渠道结果未确认');
        } finally {
            Pay::clear();
        }
    }

    private function sdkConfiguration(): array
    {
        $wechat = $this->channel === 'wechat';
        $key = PaymentCrypto::privateKey((string) ($this->config[$wechat ? 'wx_pay_cert_key_path' : 'ali_pay_private_key'] ?? ''));
        if (!openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('支付私钥无效');
        }
        if ($wechat) {
            $platform = PaymentCrypto::fileOrValue((string) $this->config['wx_pay_platform_cert_path']);
            return [
                'mode' => Pay::MODE_NORMAL,
                'mch_id' => (string) $this->config['wx_pay_mch_id'],
                'mch_secret_key' => (string) $this->config['wx_pay_secret'],
                'mch_secret_cert' => $private,
                'mch_public_cert_path' => PaymentCrypto::fileOrValue((string) $this->config['wx_pay_cert_path']),
                'wechat_public_cert_path' => [PaymentCrypto::certificateSerial($platform) => $platform],
                'mini_app_id' => (string) $this->config['wx_pay_appid'],
                'mp_app_id' => (string) $this->config['wx_pay_appid'],
                'app_id' => (string) $this->config['wx_pay_appid'],
            ];
        }
        $public = PaymentCrypto::publicKey((string) $this->config['ali_pay_public_key']);
        return [
            'app_id' => (string) $this->config['ali_pay_app_id'],
            'app_secret_cert' => $private,
            'alipay_public_cert_path' => openssl_pkey_get_details($public)['key'],
        ];
    }

    private static function result(mixed $result): array
    {
        if (!$result instanceof Collection) {
            throw new \RuntimeException('支付渠道返回格式无效');
        }
        return $result->all();
    }
}
