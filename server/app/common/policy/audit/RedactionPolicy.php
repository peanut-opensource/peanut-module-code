<?php

declare(strict_types=1);

namespace app\common\policy\audit;

final class RedactionPolicy
{
    private const REDACTED = '******';
    private const MAX_JSON_BYTES = 60000;

    private const SENSITIVE_FRAGMENTS = [
        'password', 'passwd', 'salt', 'token', 'secret', 'privatekey',
        'apikey', 'accesskey', 'aeskey', 'certificate', 'cert', 'mchkey',
        'apiv3key', 'signkey', 'encryptionkey', 'decryptionkey',
        'authorization', 'cookie', 'ticket',
    ];

    private const SENSITIVE_EXACT_KEYS = [
        'code', 'verificationcode', 'smscode', 'captchacode', 'captcha',
    ];

    public static function sanitize(mixed $value, string $key = ''): mixed
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?: '');
        if (in_array($normalized, self::SENSITIVE_EXACT_KEYS, true)) {
            return self::REDACTED;
        }
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if ($normalized !== '' && str_contains($normalized, $fragment)) {
                return self::REDACTED;
            }
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = self::sanitize($childValue, (string) $childKey);
        }
        return $value;
    }

    public static function encode(mixed $value): string
    {
        $encoded = json_encode(
            self::sanitize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if (!is_string($encoded) || strlen($encoded) > self::MAX_JSON_BYTES) {
            return '{"_redacted":"payload_unavailable"}';
        }
        return $encoded;
    }

    public static function nullableJson(?array $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        return self::encode($value);
    }
}
