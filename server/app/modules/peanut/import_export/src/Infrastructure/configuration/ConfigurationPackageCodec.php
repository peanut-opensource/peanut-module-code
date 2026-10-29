<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

use JsonException;
use Opis\JsonSchema\Validator;

/** Canonical JSON and SHA-256 codec for configuration transfer packages. */
final class ConfigurationPackageCodec
{
    public const PROTOCOL = 'peanut.configuration-transfer';
    public const IDENTITY = 'peanut.admin';
    public const SCHEMA_VERSION = 1;

    public const ADAPTER_TENANT_SETTINGS = 'tenant-settings';
    public const ADAPTER_TENANT_MODULES = 'tenant-modules';
    public const ADAPTER_EXTERNAL_BINDINGS = 'external-bindings';
    public const ADAPTER_CORE_SETTINGS = 'core-settings';

    /** @var list<string> */
    public const ADAPTERS = [
        self::ADAPTER_TENANT_SETTINGS,
        self::ADAPTER_TENANT_MODULES,
        self::ADAPTER_EXTERNAL_BINDINGS,
        self::ADAPTER_CORE_SETTINGS,
    ];

    /** @param list<array<string, mixed>> $entries @return array<string, mixed> */
    public function create(string $scope, array $entries): array
    {
        $this->assertScope($scope);
        $this->assertEntries($entries);
        $this->assertNoTenantId($entries);
        usort($entries, static fn(array $left, array $right): int => strcmp(
            (string) ($left['adapter'] ?? '') . "\0" . (string) ($left['key'] ?? ''),
            (string) ($right['adapter'] ?? '') . "\0" . (string) ($right['key'] ?? ''),
        ));
        $document = [
            'schema_version' => self::SCHEMA_VERSION,
            'protocol' => self::PROTOCOL,
            'manifest' => [
                'identity' => self::IDENTITY,
                'version' => '1.0.0',
            ],
            'scope' => $scope,
            'created_at' => gmdate('Y-m-d\\TH:i:s.v\\Z'),
            'assets' => ['strategy' => 'logical-reference-only'],
            'entries' => $entries,
            'checksum' => '',
        ];
        $document['checksum'] = $this->checksum($document);
        $this->assertValid($document);
        return $document;
    }

    /** @param array<string, mixed>|string $package @return array<string, mixed> */
    public function decode(array|string $package, ?string $expectedScope = null): array
    {
        try {
            $document = is_string($package)
                ? json_decode($package, true, 512, JSON_THROW_ON_ERROR)
                : json_decode(json_encode($package, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \runtimeException('TRANSFER_PACKAGE_INVALID');
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new \runtimeException('TRANSFER_PACKAGE_INVALID');
        }
        if ($expectedScope !== null) {
            $this->assertScope($expectedScope);
            if (($document['scope'] ?? null) !== $expectedScope) {
                throw new \runtimeException('TRANSFER_SCOPE_MISMATCH');
            }
        }
        $this->assertNoTenantId($document);
        $this->assertValid($document);
        $checksum = $document['checksum'] ?? null;
        if (!is_string($checksum) || !hash_equals($checksum, $this->checksum($document))) {
            throw new \runtimeException('TRANSFER_CHECKSUM_INVALID');
        }
        $this->assertEntries(is_array($document['entries'] ?? null) ? $document['entries'] : []);
        return $document;
    }

    /** @param array<string, mixed> $document */
    public function checksum(array $document): string
    {
        unset($document['checksum']);
        try {
            return hash('sha256', $this->canonicalJson($document));
        } catch (JsonException) {
            throw new \runtimeException('TRANSFER_PACKAGE_INVALID');
        }
    }

    /** @param array<string, mixed> $document */
    public function canonicalJson(array $document): string
    {
        return json_encode(
            $this->canonicalValue($document),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param array<string, mixed> $document */
    private function assertValid(array $document): void
    {
        $schemaPath = dirname(__DIR__, 3) . '/resources/configuration-package.schema.json';
        try {
            $schema = json_decode((string) file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);
            $value = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException|\Throwable) {
            throw new \runtimeException('TRANSFER_PACKAGE_INVALID');
        }
        if (!(new Validator())->validate($value, $schema)->isValid()) {
            throw new \runtimeException('TRANSFER_PACKAGE_INVALID');
        }
        $this->assertNoPlainSecret($document);
    }

    private function assertScope(string $scope): void
    {
        if (!in_array($scope, ['tenant', 'deployment'], true)) {
            throw new \runtimeException('TRANSFER_SCOPE_INVALID');
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function assertEntries(array $entries): void
    {
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new \runtimeException('TRANSFER_ENTRY_INVALID');
            }
            $adapter = $entry['adapter'] ?? null;
            $key = $entry['key'] ?? null;
            $secrets = $entry['secrets'] ?? null;
            if (!is_string($adapter) || !in_array($adapter, self::ADAPTERS, true)
                || !is_string($key) || $key === ''
                || !is_array($secrets) || !array_is_list($secrets)) {
                throw new \runtimeException('TRANSFER_ENTRY_INVALID');
            }
            $identity = $adapter . "\0" . $key;
            if (isset($seen[$identity])) {
                throw new \runtimeException('TRANSFER_ENTRY_DUPLICATE');
            }
            $seen[$identity] = true;

            $references = SecretReferenceCodec::references($entry['value'] ?? null);
            $metadata = [];
            foreach ($secrets as $secret) {
                if (!is_array($secret) || array_is_list($secret)
                    || array_diff(array_keys($secret), ['reference', 'state']) !== []
                    || array_diff(['reference', 'state'], array_keys($secret)) !== []
                    || !SecretReferenceCodec::isMetadata($secret)) {
                    throw new \runtimeException('TRANSFER_SECRET_METADATA_INVALID');
                }
                $reference = (string) $secret['reference'];
                if (isset($metadata[$reference])) {
                    throw new \runtimeException('TRANSFER_SECRET_METADATA_DUPLICATE');
                }
                $metadata[$reference] = (string) $secret['state'];
            }
            $actual = [];
            foreach ($references as $reference) {
                if (isset($actual[$reference['reference']])) {
                    throw new \runtimeException('TRANSFER_SECRET_REFERENCE_DUPLICATE');
                }
                $actual[$reference['reference']] = $reference['state'];
            }
            ksort($metadata, SORT_STRING);
            ksort($actual, SORT_STRING);
            if ($metadata !== $actual) {
                throw new \runtimeException('TRANSFER_SECRET_METADATA_MISMATCH');
            }
        }
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }
        return $value;
    }

    private function assertNoTenantId(mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $normalized = strtolower(str_replace(['-', '_'], '', (string) $key));
            if ($normalized === 'tenantid') {
                throw new \runtimeException('TRANSFER_PACKAGE_TENANT_ID_FORBIDDEN');
            }
            $this->assertNoTenantId($child);
        }
    }

    private function assertNoPlainSecret(mixed $value, string $key = ''): void
    {
        if (is_array($value)) {
            if (SecretReferenceCodec::isMarker($value)) {
                return;
            }
            foreach ($value as $childKey => $child) {
                $name = strtolower((string) $childKey);
                if ($name !== 'secrets' && preg_match('/(?:password|passwd|secret|token|private.?key|api.?key|credential)/i', $name) === 1) {
                    if (!SecretReferenceCodec::isMarker($child)) {
                        throw new \runtimeException('TRANSFER_SECRET_VALUE_FORBIDDEN');
                    }
                }
                $this->assertNoPlainSecret($child, $name);
            }
        }
    }
}
