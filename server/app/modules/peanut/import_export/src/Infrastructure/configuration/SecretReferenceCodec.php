<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

use JsonException;

/**
 * Converts credentials and other secret-shaped values into portable references.
 * The reference is stable, while the secret value is accepted only for the
 * duration of an import request.
 */
final class SecretReferenceCodec
{
    private const MARKER = '$secret';

    /** @var list<string> */
    private const SECRET_NAMES = [
        'api_key', 'api_secret', 'access_key', 'access_key_secret', 'app_secret',
        'certificate', 'cert', 'credential', 'credentials', 'decryption_key',
        'encryption_key', 'mch_key', 'password', 'passwd', 'private_key',
        'secret', 'secret_id', 'secret_key', 'sign_key', 'token',
    ];

    /**
     * @param list<array{reference:string,state:string}> $references
     */
    public static function redact(mixed $value, string $referenceRoot, array &$references): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $index => $item) {
                    $value[$index] = self::redact($item, $referenceRoot . '.' . $index, $references);
                }
                return $value;
            }

            foreach ($value as $key => $item) {
                $path = $referenceRoot . '.' . (string) $key;
                if (self::isSecretName((string) $key)) {
                    $value[$key] = self::marker($item, $path, $references);
                    continue;
                }
                $value[$key] = self::redact($item, $path, $references);
            }
            return $value;
        }

        return $value;
    }

    /** @param list<array{reference:string,state:string}> $references */
    public static function marker(mixed $value, string $reference, array &$references): array
    {
        $configured = self::configured($value);
        $state = $configured ? 'configured' : 'unconfigured';
        $references[] = ['reference' => $reference, 'state' => $state];

        return [
            self::MARKER => [
                'state' => $state,
                'reference' => $reference,
                'shape' => is_array($value) ? 'object' : 'scalar',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $bindings
     * @param list<string> $seen
     */
    public static function restore(mixed $value, array $bindings, array &$seen = []): mixed
    {
        if (self::isMarker($value)) {
            /** @var array<string, mixed> $marker */
            $marker = $value[self::MARKER];
            $state = $marker['state'];
            $reference = $marker['reference'];
            $seen[$reference] = true;
            if ($state === 'unconfigured') {
                return ($marker['shape'] ?? 'scalar') === 'object' ? [] : '';
            }
            if (!array_key_exists($reference, $bindings)) {
                throw new \runtimeException('TRANSFER_SECRET_REBIND_REQUIRED');
            }
            $replacement = $bindings[$reference];
            if (!self::validReplacement($replacement)) {
                throw new \runtimeException('TRANSFER_SECRET_REBIND_INVALID');
            }
            return $replacement;
        }

        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::restore($item, $bindings, $seen);
        }
        return $value;
    }

    /** @param array<string, mixed> $value */
    public static function isMarker(mixed $value): bool
    {
        if (!is_array($value) || array_is_list($value) || array_keys($value) !== [self::MARKER]) {
            return false;
        }
        $marker = $value[self::MARKER];
        if (!is_array($marker) || array_is_list($marker)) {
            return false;
        }
        $keys = array_keys($marker);
        sort($keys, SORT_STRING);
        return $keys === ['reference', 'shape', 'state']
            && in_array($marker['state'] ?? null, ['configured', 'unconfigured'], true)
            && in_array($marker['shape'] ?? null, ['object', 'scalar'], true)
            && is_string($marker['reference'])
            && preg_match('/^[a-z][a-z0-9._-]{0,190}$/D', $marker['reference']) === 1;
    }

    /** @param array<string, mixed> $metadata */
    public static function isMetadata(array $metadata): bool
    {
        return count($metadata) === 2
            && is_string($metadata['reference'] ?? null)
            && preg_match('/^[a-z][a-z0-9._-]{0,190}$/D', $metadata['reference']) === 1
            && in_array($metadata['state'] ?? null, ['configured', 'unconfigured'], true);
    }

    /** @return list<array{reference:string,state:string}> */
    public static function references(mixed $value): array
    {
        $references = [];
        self::collectReferences($value, $references);
        usort($references, static fn(array $left, array $right): int => strcmp($left['reference'], $right['reference']));
        return $references;
    }

    /** @param array<string, mixed> $bindings @param list<array{reference:string,state:string}> $references */
    public static function assertBindings(array $bindings, array $references): array
    {
        $allowed = [];
        $missing = [];
        foreach ($references as $reference) {
            $allowed[$reference['reference']] = true;
            if ($reference['state'] === 'configured') {
                if (!array_key_exists($reference['reference'], $bindings)) {
                    $missing[] = $reference['reference'];
                    continue;
                }
                if (!self::validReplacement($bindings[$reference['reference']])) {
                    throw new \runtimeException('TRANSFER_SECRET_REBIND_INVALID');
                }
                if (!self::configured($bindings[$reference['reference']])) {
                    $missing[] = $reference['reference'];
                }
            }
        }
        foreach ($bindings as $reference => $value) {
            if (!is_string($reference) || !isset($allowed[$reference])) {
                throw new \runtimeException('TRANSFER_SECRET_REFERENCE_UNKNOWN');
            }
            if (!self::validReplacement($value)) {
                throw new \runtimeException('TRANSFER_SECRET_REBIND_INVALID');
            }
        }
        sort($missing, SORT_STRING);
        return $missing;
    }

    private static function isSecretName(string $key): bool
    {
        $normalized = strtolower(trim($key));
        if (in_array($normalized, self::SECRET_NAMES, true)) {
            return true;
        }
        $collapsed = str_replace(['-', '_'], '', $normalized);
        if (in_array($collapsed, ['apikey', 'apisecret', 'password', 'passwd', 'privatekey', 'secret', 'token'], true)) {
            return true;
        }
        return preg_match('/(?:^|_)(?:api[-_]?key|api[-_]?secret|password|passwd|private[-_]?key|secret|token)(?:$|_)/i', $normalized) === 1;
    }

    private static function configured(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null && trim((string) $value) !== '';
    }

    private static function validReplacement(mixed $value): bool
    {
        if (is_array($value)) {
            try {
                return strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 16384;
            } catch (JsonException) {
                return false;
            }
        }
        return is_scalar($value) && strlen((string) $value) <= 4096;
    }

    /** @param list<array{reference:string,state:string}> $references */
    private static function collectReferences(mixed $value, array &$references): void
    {
        if (self::isMarker($value)) {
            $marker = $value[self::MARKER];
            $references[] = ['reference' => $marker['reference'], 'state' => $marker['state']];
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            self::collectReferences($item, $references);
        }
    }
}
