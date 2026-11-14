<?php

declare(strict_types=1);

namespace app\common\value\scaffold;

use RuntimeException;

final readonly class EditionProfile
{
    private const EDITIONS = ['standalone', 'multi-tenant'];

    /** @param array<string,mixed> $definition */
    private function __construct(
        public string $edition,
        public string $protocol,
        public int $generatorVersion,
        public string $sourceSha256,
        public array $definition,
    ) {}

    public static function load(string $path, string $edition): self
    {
        if (!in_array($edition, self::EDITIONS, true)) {
            throw new RuntimeException('CREATE_APP_EDITION_INVALID');
        }
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('CREATE_APP_EDITION_PROFILE_MISSING');
        }
        $raw = file_get_contents($path);
        try {
            $document = is_string($raw)
                ? json_decode($raw, true, 128, JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException $exception) {
            throw new RuntimeException('CREATE_APP_EDITION_PROFILE_INVALID_JSON', 0, $exception);
        }
        if (!is_array($document) || array_is_list($document)
            || array_keys($document) !== ['schema_version', 'protocol', 'generator_version', 'editions']
            || ($document['schema_version'] ?? null) !== 1
            || ($document['protocol'] ?? null) !== 'peanut.edition-profiles.v1'
            || ($document['generator_version'] ?? null) !== 3
            || !is_array($document['editions'] ?? null)
            || array_keys($document['editions']) !== self::EDITIONS
            || !is_string($raw)) {
            throw new RuntimeException('CREATE_APP_EDITION_PROFILE_SCHEMA_INVALID');
        }

        $definition = $document['editions'][$edition] ?? null;
        self::assertDefinition($edition, $definition);

        return new self(
            $edition,
            $document['protocol'],
            $document['generator_version'],
            hash('sha256', $raw),
            $definition,
        );
    }

    /** @return array<string,mixed> */
    public function identity(): array
    {
        return [
            'name' => $this->edition,
            'protocol' => $this->protocol,
            'generator_version' => $this->generatorVersion,
            'source_sha256' => $this->sourceSha256,
            'deployment_mode' => $this->definition['deployment_mode'],
            'data_scope_policy' => $this->definition['data_scope_policy'],
            'admin_bundle' => $this->definition['admin_bundle'],
            'platform_bundle' => $this->definition['platform_bundle'],
            'module_profile' => $this->definition['module_profile'],
            'tenant_bootstrap' => $this->definition['tenant_bootstrap'],
            'schema' => $this->definition['schema'],
        ];
    }

    /** @param mixed $definition */
    private static function assertDefinition(string $edition, mixed $definition): void
    {
        if (!is_array($definition) || array_is_list($definition)
            || array_keys($definition) !== [
                'deployment_mode', 'data_scope_policy', 'admin_bundle',
                'platform_bundle', 'module_profile', 'tenant_bootstrap', 'schema',
            ]
            || ($definition['deployment_mode'] ?? null) !== $edition
            || ($definition['admin_bundle'] ?? null) !== $edition
            || !is_bool($definition['platform_bundle'] ?? null)
            || ($definition['module_profile'] ?? null) !== 'official-default'
            || ($definition['tenant_bootstrap'] ?? null) !== [
                'kind' => 'real-default-tenant',
                'code' => 'default',
                'tenant_identity' => 'required',
                'rbac' => 'required',
                'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
                'module_lifecycle' => 'required',
            ]
            || !is_array($definition['schema'] ?? null)
            || array_keys($definition['schema']) !== [
                'projection', 'retains_core_tenant_identity',
                'legacy_tenantless_upgrade', 'table_rules',
            ]
            || ($definition['schema']['retains_core_tenant_identity'] ?? null) !== true
            || ($definition['schema']['projection'] ?? null) !== 'tenant-owned-v1'
            || ($definition['schema']['table_rules'] ?? null) !== []) {
            throw new RuntimeException('CREATE_APP_EDITION_PROFILE_SCHEMA_INVALID');
        }

        $expectedLegacyUpgrade = $edition === 'standalone'
            ? 'blocked-manual-migration-required'
            : 'not-applicable';
        if (($definition['data_scope_policy'] ?? null) !== 'app\\common\\tenancy\\MultiTenantDataScopePolicy'
            || ($definition['schema']['legacy_tenantless_upgrade'] ?? null) !== $expectedLegacyUpgrade
            || ($edition === 'standalone' && $definition['platform_bundle'] !== false)
            || ($edition === 'multi-tenant' && $definition['platform_bundle'] !== true)) {
            throw new RuntimeException('CREATE_APP_EDITION_PROFILE_SCHEMA_INVALID');
        }
    }
}
