<?php

declare(strict_types=1);

namespace app\platform\value\ops;

use PeanutAdmin\Modules\Ops\Infrastructure\PairedBackupProvider;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Canonical, path-free receipt produced by the trusted restore worker. */
final readonly class RestoreVerificationEvidence
{
    public const SCHEMA_VERSION = 4;

    /** @param array<string,mixed> $data */
    private function __construct(private array $data) {}

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
        }
        if (!is_array($data)) {
            throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
        }

        self::exactKeys($data, [
            'schema_version', 'backup_reference_key', 'provider_key', 'target_key',
            'manifest_sha256', 'source', 'target', 'verification', 'isolation',
            'cleanup_state', 'verified_at',
        ]);
        if ($data['schema_version'] !== self::SCHEMA_VERSION
            || !is_string($data['backup_reference_key'])
            || preg_match('/^backup_[a-f0-9]{32}$/D', $data['backup_reference_key']) !== 1
            || $data['provider_key'] !== PairedBackupProvider::PROVIDER_KEY
            || $data['target_key'] !== PairedBackupProvider::RESTORE_TARGET_KEY
            || !is_string($data['manifest_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $data['manifest_sha256']) !== 1
            || $data['cleanup_state'] !== 'removed'
        ) {
            throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
        }

        $source = self::map($data['source']);
        self::exactKeys($source, ['commit', 'tree']);
        foreach (['commit', 'tree'] as $key) {
            if (!is_string($source[$key]) || preg_match('/^[a-f0-9]{40}$/D', $source[$key]) !== 1) {
                throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
            }
        }

        $target = self::map($data['target']);
        self::exactKeys($target, [
            'deployment_resource_id', 'database_resource_id', 'runtime_resource_id',
            'compose_project', 'database_name', 'public_storage_directory', 'private_storage_directory', 'installation_directory',
        ]);
        $registered = self::registeredTarget();
        if ($target !== [
            'deployment_resource_id' => $registered['deployment'],
            'database_resource_id' => $registered['database'],
            'runtime_resource_id' => $registered['runtime'],
            'compose_project' => $registered['project'],
            'database_name' => $registered['database_name'],
            'public_storage_directory' => 'server/public',
            'private_storage_directory' => 'server/private/storage',
            'installation_directory' => 'server/private/installation',
        ]) {
            throw new RuntimeException('OPS_RESTORE_TARGET_INVALID');
        }

        $verification = self::map($data['verification']);
        self::exactKeys($verification, [
            'table_count', 'schema_migration_count', 'critical_table_count',
            'account_count', 'tenant_count', 'tenant_member_count',
            'storage_file_count', 'storage_bytes',
            'private_storage_file_count', 'private_storage_bytes',
            'installation_file_count', 'installation_bytes',
        ]);
        foreach ($verification as $value) {
            if (!is_int($value) || $value < 0) {
                throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
            }
        }
        if ($verification['table_count'] < 1
            || $verification['schema_migration_count'] < 1
            || $verification['critical_table_count'] !== 6
            || $verification['account_count'] < 1
            || $verification['tenant_count'] < 1
            || $verification['tenant_member_count'] < 1
        ) {
            throw new RuntimeException('OPS_RESTORE_DATA_INVALID');
        }

        $isolation = self::map($data['isolation']);
        self::exactKeys($isolation, [
            'published_port_count', 'protected_runtime_before_sha256',
            'protected_runtime_after_sha256',
        ]);
        if ($isolation['published_port_count'] !== 0
            || !is_string($isolation['protected_runtime_before_sha256'])
            || !is_string($isolation['protected_runtime_after_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $isolation['protected_runtime_before_sha256']) !== 1
            || !hash_equals(
                $isolation['protected_runtime_before_sha256'],
                $isolation['protected_runtime_after_sha256'],
            )
        ) {
            throw new RuntimeException('OPS_RESTORE_ISOLATION_VIOLATION');
        }

        if (!is_string($data['verified_at']) || !self::validInstant($data['verified_at'])) {
            throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
        }

        return new self($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function canonicalJson(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** Resolve the logical restore target through the installed APP registry. */
    private static function registeredTarget(): array
    {
        require_once dirname(__DIR__, 4) . '/database/environment-guard.php';
        $registry = \projectResourceRegistry();
        $rows = [];
        $walk = function (array $node) use (&$walk, &$rows): void {
            if (isset($node['stable_resource_id'])) {
                $rows[] = $node;
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($registry);
        $find = static function (string $id) use ($rows): array {
            $found = array_values(array_filter($rows, static fn (array $row): bool => ($row['stable_resource_id'] ?? null) === $id));
            if (count($found) !== 1) {
                throw new RuntimeException('OPS_RESTORE_TARGET_NOT_REGISTERED');
            }
            return $found[0];
        };
        $source = \registeredApplicationBackupSource($registry, $rows);
        if (!is_string($source['restore_verification_resource_id'] ?? null)) {
            throw new RuntimeException('OPS_RESTORE_SOURCE_NOT_REGISTERED');
        }
        $target = $find((string) $source['restore_verification_resource_id']);
        $database = $find((string) ($target['database_resource_id'] ?? ''));
        $runtime = $find((string) ($target['application_resource_id'] ?? ''));
        if (($target['restore_target_key'] ?? null) !== PairedBackupProvider::RESTORE_TARGET_KEY
            || ($target['source_deployment_resource_id'] ?? null) !== $source['stable_resource_id']
            || ($target['compose_file'] ?? null) !== 'server/docker/compose.yaml'
            || ($target['compose_project'] ?? null) === ($source['compose_project'] ?? null)
            || ($target['database_resource_id'] ?? null) === $source['database_resource_id']
            || ($database['database'] ?? null) === ($find((string) $source['database_resource_id'])['database'] ?? null)
            || ($database['namespace'] ?? null) !== ($target['deployment_root'] ?? '') . '/server/docker/mysql'
            || ($database['compose_project'] ?? null) !== ($target['compose_project'] ?? null)
            || ($runtime['compose_project'] ?? null) !== ($target['compose_project'] ?? null)
            || ($target['fallback'] ?? null) !== 'none'
            || ($database['fallback'] ?? null) !== 'none'
            || ($runtime['fallback'] ?? null) !== 'none'
        ) {
            throw new RuntimeException('OPS_RESTORE_TARGET_INVALID');
        }
        return ['deployment' => $target['stable_resource_id'], 'database' => $database['stable_resource_id'],
            'runtime' => $runtime['stable_resource_id'], 'project' => $target['compose_project'],
            'database_name' => $database['database']];
    }

    private static function validInstant(string $value): bool
    {
        return DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z',
            $value,
            new DateTimeZone('UTC'),
        ) !== false;
    }

    /** @return array<string,mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
        }
        return $value;
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function exactKeys(array $value, array $keys): void
    {
        if (array_keys($value) !== $keys) {
            throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
        }
    }
}
