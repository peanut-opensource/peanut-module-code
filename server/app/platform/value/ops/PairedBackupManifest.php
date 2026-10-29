<?php

declare(strict_types=1);

namespace app\platform\value\ops;

use PeanutAdmin\Modules\Ops\Infrastructure\PairedBackupProvider;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Versioned, path-free contract for one verified database, two storage archives,
 * and the installation identity archive.
 *
 * Artifact bytes remain owned by a trusted deployment adapter. This value
 * object accepts only their safe identity and integrity projection.
 */
final readonly class PairedBackupManifest
{
    public const SCHEMA_VERSION = 4;
    public const DATABASE_ARTIFACT = 'database.sql.gz';
    public const PUBLIC_FILES_ARTIFACT = 'php-storage.tar.gz';
    public const PRIVATE_FILES_ARTIFACT = 'php-private-storage.tar.gz';
    public const INSTALLATION_ARTIFACT = 'php-installation.tar.gz';
    private const CAPACITY_HEADROOM_BYTES = 1073741824;

    /** @param array<string, mixed> $manifest */
    private function __construct(private array $manifest) {}

    public static function fromJson(string $json): self
    {
        if ($json === '' || strlen($json) > 65536) {
            self::invalid();
        }
        try {
            $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }
        if (!is_array($manifest)) {
            self::invalid();
        }
        return self::fromArray($manifest);
    }

    /** @param array<string, mixed> $manifest */
    public static function fromArray(array $manifest): self
    {
        self::exactKeys($manifest, [
            'schema_version',
            'backup_reference_key',
            'provider_key',
            'source',
            'resources',
            'runtime',
            'consistency_window',
            'capacity_preflight',
            'artifacts',
            'responsibility',
        ]);
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !is_string($manifest['backup_reference_key'] ?? null)
            || preg_match('/^backup_[a-f0-9]{32}$/D', $manifest['backup_reference_key']) !== 1
            || ($manifest['provider_key'] ?? null) !== PairedBackupProvider::PROVIDER_KEY
        ) {
            self::invalid();
        }

        $source = self::map($manifest['source'] ?? null);
        self::exactKeys($source, ['commit', 'tree', 'release_key']);
        self::commit($source['commit'] ?? null);
        self::commit($source['tree'] ?? null);
        if (($source['release_key'] ?? null) !== null
            && (!is_string($source['release_key'])
                || preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $source['release_key']) !== 1)
        ) {
            self::invalid();
        }

        $resources = self::map($manifest['resources'] ?? null);
        self::exactKeys($resources, ['deployment', 'database', 'application', 'backup']);
        foreach ($resources as $resourceId) {
            self::stableKey($resourceId, 128);
        }
        $registered = self::registeredResources($resources);

        $runtime = self::map($manifest['runtime'] ?? null);
        self::exactKeys($runtime, [
            'compose_project',
            'compose_file',
            'compose_profile',
            'database_name',
            'public_storage_directory',
            'private_storage_directory',
            'installation_directory',
            'images',
        ]);
        if (!is_string($runtime['compose_project'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $runtime['compose_project']) !== 1
        ) {
            self::invalid();
        }
        if (($runtime['compose_file'] ?? null) !== 'server/docker/compose.yaml' || ($runtime['compose_profile'] ?? null) !== 'default') {
            self::invalid();
        }
        self::stableKey($runtime['compose_profile'] ?? null, 64);
        self::databaseName($runtime['database_name'] ?? null);
        if ($runtime['compose_project'] !== $registered['deployment']['compose_project']
            || $runtime['database_name'] !== $registered['database']['database']
        ) {
            self::invalid();
        }
        self::storageDirectory($runtime['public_storage_directory'] ?? null, 'server/public');
        self::storageDirectory($runtime['private_storage_directory'] ?? null, 'server/private/storage');
        self::storageDirectory($runtime['installation_directory'] ?? null, 'server/private/installation');
        if (count(array_unique([
            $runtime['public_storage_directory'],
            $runtime['private_storage_directory'],
            $runtime['installation_directory'],
        ])) !== 3) {
            self::invalid();
        }
        $images = self::images($runtime['images'] ?? null);

        $window = self::map($manifest['consistency_window'] ?? null);
        self::exactKeys($window, ['mode', 'started_at', 'completed_at']);
        if (($window['mode'] ?? null) !== 'application-write-quiescence') {
            self::invalid();
        }
        $startedAt = self::instant($window['started_at'] ?? null);
        $completedAt = self::instant($window['completed_at'] ?? null);
        if ($completedAt < $startedAt) {
            self::invalid();
        }

        $capacity = self::map($manifest['capacity_preflight'] ?? null);
        self::exactKeys($capacity, ['source_bytes', 'required_bytes', 'available_bytes', 'available_inodes']);
        $sourceBytes = self::nonNegativeInteger($capacity['source_bytes'] ?? null);
        $requiredBytes = self::positiveInteger($capacity['required_bytes'] ?? null);
        $availableBytes = self::positiveInteger($capacity['available_bytes'] ?? null);
        self::positiveInteger($capacity['available_inodes'] ?? null);
        if ($sourceBytes > intdiv(PHP_INT_MAX - self::CAPACITY_HEADROOM_BYTES, 2)
            || $requiredBytes !== ($sourceBytes * 2) + self::CAPACITY_HEADROOM_BYTES
            || $availableBytes <= $requiredBytes
        ) {
            self::invalid();
        }

        $artifacts = self::artifacts($manifest['artifacts'] ?? null);

        $responsibility = self::map($manifest['responsibility'] ?? null);
        self::exactKeys($responsibility, ['retention_owner', 'cleanup_owner', 'restore_owner']);
        foreach ($responsibility as $ownerKey) {
            self::stableKey($ownerKey, 96);
        }

        return new self([
            'schema_version' => self::SCHEMA_VERSION,
            'backup_reference_key' => $manifest['backup_reference_key'],
            'provider_key' => PairedBackupProvider::PROVIDER_KEY,
            'source' => [
                'commit' => $source['commit'],
                'tree' => $source['tree'],
                'release_key' => $source['release_key'],
            ],
            'resources' => [
                'deployment' => $resources['deployment'],
                'database' => $resources['database'],
                'application' => $resources['application'],
                'backup' => $resources['backup'],
            ],
            'runtime' => [
                'compose_project' => $runtime['compose_project'],
                'compose_file' => $runtime['compose_file'],
                'compose_profile' => $runtime['compose_profile'],
                'database_name' => $runtime['database_name'],
                'public_storage_directory' => $runtime['public_storage_directory'],
                'private_storage_directory' => $runtime['private_storage_directory'],
                'installation_directory' => $runtime['installation_directory'],
                'images' => $images,
            ],
            'consistency_window' => [
                'mode' => 'application-write-quiescence',
                'started_at' => $window['started_at'],
                'completed_at' => $window['completed_at'],
            ],
            'capacity_preflight' => [
                'source_bytes' => $sourceBytes,
                'required_bytes' => $requiredBytes,
                'available_bytes' => $availableBytes,
                'available_inodes' => $capacity['available_inodes'],
            ],
            'artifacts' => $artifacts,
            'responsibility' => [
                'retention_owner' => $responsibility['retention_owner'],
                'cleanup_owner' => $responsibility['cleanup_owner'],
                'restore_owner' => $responsibility['restore_owner'],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->manifest;
    }

    public function backupReferenceKey(): string
    {
        return $this->manifest['backup_reference_key'];
    }

    public function canonicalJson(): string
    {
        return json_encode(
            $this->manifest,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /** @return list<array{role:string,reference:string,digest:string}> */
    private static function images(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 3) {
            self::invalid();
        }
        $expectedRoles = ['php', 'nginx', 'mysql'];
        $normalized = [];
        foreach ($value as $index => $image) {
            $image = self::map($image);
            self::exactKeys($image, ['role', 'reference', 'digest']);
            if (($image['role'] ?? null) !== $expectedRoles[$index]
                || !is_string($image['reference'] ?? null)
                || strlen($image['reference']) < 3
                || strlen($image['reference']) > 255
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/@:-]*$/D', $image['reference']) !== 1
                || !is_string($image['digest'] ?? null)
                || preg_match('/^sha256:[a-f0-9]{64}$/D', $image['digest']) !== 1
            ) {
                self::invalid();
            }
            $normalized[] = [
                'role' => $image['role'],
                'reference' => $image['reference'],
                'digest' => $image['digest'],
            ];
        }
        return $normalized;
    }

    /** @return list<array{kind:string,filename:string,bytes:int,sha256:string}> */
    private static function artifacts(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 4) {
            self::invalid();
        }
        $expected = [
            ['kind' => 'database', 'filename' => self::DATABASE_ARTIFACT],
            ['kind' => 'public_files', 'filename' => self::PUBLIC_FILES_ARTIFACT],
            ['kind' => 'private_files', 'filename' => self::PRIVATE_FILES_ARTIFACT],
            ['kind' => 'installation_state', 'filename' => self::INSTALLATION_ARTIFACT],
        ];
        $normalized = [];
        foreach ($value as $index => $artifact) {
            $artifact = self::map($artifact);
            self::exactKeys($artifact, ['kind', 'filename', 'bytes', 'sha256']);
            if (($artifact['kind'] ?? null) !== $expected[$index]['kind']
                || ($artifact['filename'] ?? null) !== $expected[$index]['filename']
                || !is_string($artifact['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256']) !== 1
            ) {
                self::invalid();
            }
            self::positiveInteger($artifact['bytes'] ?? null);
            $normalized[] = [
                'kind' => $artifact['kind'],
                'filename' => $artifact['filename'],
                'bytes' => $artifact['bytes'],
                'sha256' => $artifact['sha256'],
            ];
        }
        return $normalized;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            self::invalid();
        }
        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private static function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    private static function stableKey(mixed $value, int $maximum): void
    {
        if (!is_string($value)
            || strlen($value) > $maximum
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1
        ) {
            self::invalid();
        }
    }

    private static function databaseName(mixed $value): void
    {
        if (!is_string($value)
            || strlen($value) > 64
            || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $value) !== 1
        ) {
            self::invalid();
        }
    }

    private static function storageDirectory(mixed $value, string $expected): void
    {
        if ($value !== $expected) {
            self::invalid();
        }
    }

    /** @param array<string,mixed> $resources */
    private static function registeredResources(array $resources): array
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
                self::invalid();
            }
            return $found[0];
        };
        $deployment = $find($resources['deployment']);
        $database = $find($resources['database']);
        $application = $find($resources['application']);
        $backup = $find($resources['backup']);
        if ($deployment !== \registeredApplicationBackupSource($registry, $rows)
            || ($deployment['database_resource_id'] ?? null) !== $resources['database']
            || ($deployment['application_resource_id'] ?? null) !== $resources['application']
            || ($deployment['backup_resource_id'] ?? null) !== $resources['backup']
            || ($deployment['compose_file'] ?? null) !== 'server/docker/compose.yaml'
            || ($database['namespace'] ?? null) !== ($deployment['deployment_root'] ?? '') . '/server/docker/mysql'
            || ($database['compose_project'] ?? null) !== ($deployment['compose_project'] ?? null)
            || ($application['compose_project'] ?? null) !== ($deployment['compose_project'] ?? null)
            || ($backup['deployment_resource_id'] ?? null) !== $resources['deployment']
            || ($backup['path'] ?? null) !== ($deployment['deployment_root'] ?? '') . '/backups'
            || ($deployment['fallback'] ?? null) !== 'none'
            || ($database['fallback'] ?? null) !== 'none'
            || ($application['fallback'] ?? null) !== 'none'
            || ($backup['fallback'] ?? null) !== 'none'
        ) {
            self::invalid();
        }
        return ['deployment' => $deployment, 'database' => $database];
    }

    private static function commit(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{40}$/D', $value) !== 1) {
            self::invalid();
        }
    }

    private static function instant(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:19|20)[0-9]{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\.[0-9]{3}Z$/D', $value) !== 1
        ) {
            self::invalid();
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value);
        if ($instant === false || $instant->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            self::invalid();
        }
        return $value;
    }

    private static function nonNegativeInteger(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            self::invalid();
        }
        return $value;
    }

    private static function positiveInteger(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            self::invalid();
        }
        return $value;
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid paired backup manifest.');
    }
}
