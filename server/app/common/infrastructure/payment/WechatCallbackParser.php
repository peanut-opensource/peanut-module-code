<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\contract\payment\CallbackParserInterface;
use app\common\dto\payment\CallbackRequest;
use app\common\dto\payment\PaymentEvent;
use app\common\infrastructure\payment\PaymentCrypto;

final class WechatCallbackParser implements CallbackParserInterface
{
    private array $config;
    private int $clockTolerance;

    public function __construct(array $config, int $clockTolerance = 300)
    {
        $this->config = $config;
        $this->clockTolerance = $clockTolerance;
    }

    public function parse(CallbackRequest $request): PaymentEvent
    {
        $this->assertConfig();
        $timestamp = $request->header('Wechatpay-Timestamp');
        $nonce = $request->header('Wechatpay-Nonce');
        $serial = strtoupper($request->header('Wechatpay-Serial'));
        $signature = $request->header('Wechatpay-Signature');
        $signatureType = $request->header('Wechatpay-Signature-Type');
        if ($timestamp === '' || !ctype_digit($timestamp) || $nonce === '' || $serial === '' || $signature === '') {
            throw new \RuntimeException('微信支付回调签名头不完整');
        }
        if ($signatureType !== '' && strtoupper($signatureType) !== 'WECHATPAY2-SHA256-RSA2048') {
            throw new \RuntimeException('微信支付回调签名算法不受支持');
        }
        if (abs(time() - (int) $timestamp) > $this->clockTolerance) {
            throw new \RuntimeException('微信支付回调时间戳已过期');
        }
        $certificateText = PaymentCrypto::fileOrValue((string) $this->config['wx_pay_platform_cert_path']);
        $certificateInfo = openssl_x509_parse($certificateText);
        $certificateSerial = is_array($certificateInfo)
            ? strtoupper((string) ($certificateInfo['serialNumberHex'] ?? ''))
            : '';
        if ($certificateSerial === '' || !hash_equals($certificateSerial, $serial)) {
            throw new \RuntimeException('微信支付平台证书序列号不匹配');
        }
        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false || openssl_verify(
            $timestamp . "\n" . $nonce . "\n" . $request->body() . "\n",
            $decodedSignature,
            PaymentCrypto::publicKey($certificateText),
            OPENSSL_ALGO_SHA256,
        ) !== 1) {
            throw new \RuntimeException('微信支付回调签名验证失败');
        }
        $envelope = json_decode($request->body(), true);
        if (!is_array($envelope) || !is_array($envelope['resource'] ?? null)) {
            throw new \RuntimeException('微信支付回调格式异常');
        }
        $data = $this->decryptResource($envelope['resource']);
        $mchId = trim((string) ($data['mchid'] ?? ''));
        $appId = trim((string) ($data['appid'] ?? ''));
        if (!hash_equals(trim((string) $this->config['wx_pay_mch_id']), $mchId)
            || !hash_equals(trim((string) $this->config['wx_pay_appid']), $appId)) {
            throw new \RuntimeException('微信支付回调商户或应用身份不匹配');
        }
        $amount = $data['amount'] ?? [];
        return new PaymentEvent(
            'wechat',
            (string) ($data['out_trade_no'] ?? ''),
            (string) ($data['transaction_id'] ?? ''),
            (int) ($amount['total'] ?? 0),
            (string) ($amount['currency'] ?? ''),
            match (strtoupper((string) ($data['trade_state'] ?? ''))) {
                'SUCCESS' => 'success',
                'CLOSED', 'REVOKED', 'PAYERROR' => 'failed',
                default => 'pending',
            },
            $mchId,
            $appId,
        );
    }

    private function assertConfig(): void
    {
        if ((int) ($this->config['wx_pay_status'] ?? 0) !== 1) {
            throw new \RuntimeException('微信支付未开启');
        }
        foreach (['wx_pay_appid', 'wx_pay_mch_id', 'wx_pay_secret', 'wx_pay_platform_cert_path'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new \RuntimeException('微信支付回调配置不完整:' . $key);
            }
        }
        if (strlen((string) $this->config['wx_pay_secret']) !== 32) {
            throw new \RuntimeException('微信支付 APIv3 密钥必须为 32 字节');
        }
    }

    private function decryptResource(array $resource): array
    {
        if (strtoupper((string) ($resource['algorithm'] ?? '')) !== 'AEAD_AES_256_GCM') {
            throw new \RuntimeException('微信支付回调加密算法不受支持');
        }
        $cipherWithTag = base64_decode((string) ($resource['ciphertext'] ?? ''), true);
        $nonce = (string) ($resource['nonce'] ?? '');
        if ($cipherWithTag === false || strlen($cipherWithTag) <= 16 || $nonce === '') {
            throw new \RuntimeException('微信支付回调密文无效');
        }
        $ciphertext = substr($cipherWithTag, 0, -16);
        $tag = substr($cipherWithTag, -16);
        $plain = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            (string) $this->config['wx_pay_secret'],
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            (string) ($resource['associated_data'] ?? ''),
        );
        $decoded = $plain === false ? null : json_decode($plain, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('微信支付回调解密失败');
        }
        return $decoded;
    }
}
