<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\dto\payment\TransportResponse;

final class PaymentCrypto
{
    public static function resolveFile(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $candidates = [$path];
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            if (function_exists('root_path')) {
                $candidates[] = root_path() . ltrim($path, '/\\');
            }
            if (function_exists('public_path')) {
                $candidates[] = public_path() . ltrim($path, '/\\');
            }
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    public static function privateKey(string $value)
    {
        $value = self::fileOrValue($value);
        if ($value !== '' && !str_contains($value, 'BEGIN')) {
            $value = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $value), 64, "\n")
                . "-----END PRIVATE KEY-----\n";
        }
        $key = openssl_pkey_get_private($value);
        if ($key === false) {
            throw new \RuntimeException('支付私钥无效');
        }
        return $key;
    }

    public static function publicKey(string $value)
    {
        $value = self::fileOrValue($value);
        if ($value !== '' && !str_contains($value, 'BEGIN')) {
            $value = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $value), 64, "\n")
                . "-----END PUBLIC KEY-----\n";
        }
        $key = openssl_pkey_get_public($value);
        if ($key === false) {
            throw new \RuntimeException('支付公钥或平台证书无效');
        }
        return $key;
    }

    public static function fileOrValue(string $value): string
    {
        $path = self::resolveFile($value);
        if ($path !== '') {
            $content = file_get_contents($path);
            return $content === false ? '' : $content;
        }
        return trim($value);
    }

    public static function sign(string $content, $privateKey): string
    {
        $signature = '';
        if (!openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('支付请求签名失败');
        }
        return base64_encode($signature);
    }

    public static function decimalToCent(string $amount): int
    {
        $amount = trim($amount);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new \RuntimeException('支付回调金额无效');
        }
        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $yuan * 100) + (int) str_pad($cent, 2, '0');
    }

    public static function certificateSerial(string $certificate): string
    {
        $info = openssl_x509_parse(self::fileOrValue($certificate));
        $serial = is_array($info) ? strtoupper((string) ($info['serialNumberHex'] ?? '')) : '';
        if ($serial === '') {
            throw new \RuntimeException('无法读取支付证书序列号');
        }
        return $serial;
    }

    /** 微信支付 API v3 响应必须由配置的平台证书签名，不能只信任 HTTP 200。 */
    public static function verifyWechatResponse(
        TransportResponse $response,
        string $platformCertificate,
        int $clockTolerance = 300,
    ): void {
        $timestamp = $response->header('Wechatpay-Timestamp');
        $nonce = $response->header('Wechatpay-Nonce');
        $serial = strtoupper($response->header('Wechatpay-Serial'));
        $signature = base64_decode($response->header('Wechatpay-Signature'), true);
        if ($timestamp === '' || !ctype_digit($timestamp) || $nonce === '' || $serial === ''
            || $signature === false) {
            throw new \RuntimeException('微信支付响应签名头不完整');
        }
        if (abs(time() - (int) $timestamp) > $clockTolerance) {
            throw new \RuntimeException('微信支付响应时间戳已过期');
        }
        $certificate = self::fileOrValue($platformCertificate);
        if (!hash_equals(self::certificateSerial($certificate), $serial)
            || openssl_verify(
                $timestamp . "\n" . $nonce . "\n" . $response->body() . "\n",
                $signature,
                self::publicKey($certificate),
                OPENSSL_ALGO_SHA256,
            ) !== 1) {
            throw new \RuntimeException('微信支付响应验签失败');
        }
    }

    public static function verifyAlipayResponse(
        string $rawResponse,
        array $decoded,
        string $publicKey,
        string $responseKey,
    ): void {
        $signature = base64_decode((string) ($decoded['sign'] ?? ''), true);
        $signedContent = self::extractJsonObject($rawResponse, $responseKey);
        if ($signature === false || $signedContent === ''
            || openssl_verify(
                $signedContent,
                $signature,
                self::publicKey($publicKey),
                OPENSSL_ALGO_SHA256,
            ) !== 1) {
            throw new \RuntimeException('支付宝响应验签失败');
        }
    }

    /** 提取支付宝实际签名的响应节点原文，避免重新编码 JSON 改变签名内容。 */
    private static function extractJsonObject(string $json, string $key): string
    {
        $keyPosition = strpos($json, '"' . $key . '"');
        if ($keyPosition === false) {
            return '';
        }
        $start = strpos($json, '{', $keyPosition);
        if ($start === false) {
            return '';
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($json);
        for ($index = $start; $index < $length; $index++) {
            $char = $json[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($json, $start, $index - $start + 1);
                }
            }
        }
        return '';
    }
}
