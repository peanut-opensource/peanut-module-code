<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Infrastructure\Channel;

final class PaymentCrypto
{
    public static function resolveFile(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || str_contains($path, 'BEGIN') || str_contains($path, '..')) {
            return '';
        }
        // Reject arbitrary absolute filesystem paths; only allow relative paths inside managed cert directories.
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return '';
        }
        $allowedRoots = [];
        if (function_exists('root_path')) {
            $root = rtrim(root_path(), '/\\');
            $allowedRoots[] = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'certs';
            $allowedRoots[] = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'certs';
        }
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        foreach ($allowedRoots as $allowedRoot) {
            $realRoot = realpath($allowedRoot);
            if ($realRoot === false || !is_dir($realRoot)) {
                continue;
            }
            $candidate = realpath($realRoot . DIRECTORY_SEPARATOR . $relative);
            if ($candidate === false || !is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($candidate, $prefix)) {
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
