<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Infrastructure\Storage;

use think\facade\Config;

final class StorageCredentialCipher
{
    public const KEY_VERSION = 'aes-256-gcm-v1';
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'peanut-admin.storage-credentials';

    /** @return array{ciphertext:string,key_version:string} */
    public static function encrypt(array $credentials): array
    {
        $plain = json_encode(self::normalize($credentials), JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if (!is_string($ciphertext) || $tag === '') {
            throw new \RuntimeException('存储凭据加密失败');
        }
        return ['ciphertext' => implode('.', [self::KEY_VERSION, base64_encode($iv), base64_encode($tag), base64_encode($ciphertext)]), 'key_version' => self::KEY_VERSION];
    }

    /** @return array{access_key:string,secret_key:string} */
    public static function decrypt(string $ciphertext, string $keyVersion): array
    {
        if ($keyVersion !== self::KEY_VERSION) {
            throw new \RuntimeException('存储凭据密钥版本不支持');
        }
        $parts = explode('.', $ciphertext);
        if (count($parts) !== 4 || $parts[0] !== self::KEY_VERSION) {
            throw new \RuntimeException('存储凭据密文格式无效');
        }
        [, $ivEncoded, $tagEncoded, $payloadEncoded] = $parts;
        $iv = base64_decode($ivEncoded, true);
        $tag = base64_decode($tagEncoded, true);
        $payload = base64_decode($payloadEncoded, true);
        if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($payload) || $payload === '') {
            throw new \RuntimeException('存储凭据密文格式无效');
        }
        $plain = openssl_decrypt($payload, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if (!is_string($plain)) {
            throw new \RuntimeException('存储凭据无法解密');
        }
        $decoded = json_decode($plain, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('存储凭据内容无效');
        }
        return self::normalize($decoded);
    }

    /** @return array{access_key:string,secret_key:string} */
    private static function normalize(array $credentials): array
    {
        $accessKey = trim((string) ($credentials['access_key'] ?? ''));
        $secretKey = trim((string) ($credentials['secret_key'] ?? ''));
        if ($accessKey === '' || $secretKey === '') {
            throw new \InvalidArgumentException('存储凭据不完整');
        }
        return ['access_key' => $accessKey, 'secret_key' => $secretKey];
    }

    private static function key(): string
    {
        $master = trim((string) Config::get('peanut.storage_credential_master_key', ''));
        if ($master === '') {
            throw new \RuntimeException('存储凭据主密钥未配置');
        }
        if (strlen($master) < 32) {
            throw new \RuntimeException('存储凭据主密钥长度不足');
        }
        return hash('sha256', $master, true);
    }
}
