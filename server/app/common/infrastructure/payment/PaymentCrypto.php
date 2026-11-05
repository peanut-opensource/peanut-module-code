<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

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

}
