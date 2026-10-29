<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

/** Shared entry construction and secret-safe comparison helpers. */
final class ConfigurationTransferValue
{
    /** @return array{adapter:string,key:string,value:mixed,secrets:list<array{reference:string,state:string}>} */
    public static function entry(string $adapter, string $key, mixed $value): array
    {
        $references = [];
        $redacted = SecretReferenceCodec::redact(
            $value,
            self::referenceRoot($adapter, $key),
            $references,
        );
        usort($references, static fn(array $left, array $right): int => strcmp(
            $left['reference'],
            $right['reference'],
        ));

        return [
            'adapter' => $adapter,
            'key' => $key,
            'value' => $redacted,
            'secrets' => $references,
        ];
    }

    public static function referenceRoot(string $adapter, string $key): string
    {
        $normalized = strtolower($adapter . '.' . $key);
        $normalized = preg_replace('/[^a-z0-9._-]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '.-');
        if ($normalized === '' || strlen($normalized) > 190) {
            throw new \runtimeException('TRANSFER_SECRET_REFERENCE_INVALID');
        }
        return $normalized;
    }

    /** Markers compare by state, never by their reference or value. */
    public static function comparable(mixed $value): mixed
    {
        if (SecretReferenceCodec::isMarker($value)) {
            return ['__secret_state' => $value['$secret']['state']];
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'comparable'], $value);
        }
        $result = [];
        foreach ($value as $key => $child) {
            $result[(string) $key] = self::comparable($child);
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function __construct() {}
}
