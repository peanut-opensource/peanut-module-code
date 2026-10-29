<?php

declare(strict_types=1);

namespace app\platform\value\ops;

use app\common\value\installation\ApplicationReleaseVersions;
use RuntimeException;

/**
 * Validates the deployment-staged, fixed-path upgrade target bundle.
 *
 * The bundle is a privileged deployment input. HTTP callers cannot select a
 * path, URL, command, release key, or credential.
 */
final readonly class PlatformUpgradeTarget
{
    private const TARGET_DIRECTORY = '.peanut/upgrade-target';
    private const VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D';
    private const APPLICATION_VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D';
    private const RELEASE_KEY = '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D';
    private const KERNEL_VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D';
    private const COMMIT = '/^[a-f0-9]{40}$/D';
    private const SHA256 = '/^[a-f0-9]{64}$/D';
    private const MIGRATION = '/^[0-9]{8}-[a-z0-9][a-z0-9_-]*$/D';
    private const SLUG = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';
    private const PACKAGE_IDENTITY = '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D';

    /**
     * @param array{key:string,commit:string,tree:string,qualification:array<string,mixed>} $release
     * @param array{from_version:string,from_manifest_sha256:string,to_version:string,to_manifest_sha256:string} $scaffold
     * @param array{from:array{inventory_sha256:string,files:list<array{migration_id:string,sha256:string}>},to:array{inventory_sha256:string,files:list<array{migration_id:string,sha256:string}>}} $migrations
     * @param array{lock_sha256:string,kernel_version:string} $modules
     * @param array{slug:string,package_identity:string,application_version:string,product_release:string} $application
     * @param array{version:string,source_commit:string,source_tree:string,inventory_sha256:string} $sourceTemplate
     * @param array{version:string,source_commit:string,source_tree:string,inventory_sha256:string} $template
     */
    private function __construct(
        public array $release,
        public array $scaffold,
        public array $migrations,
        public array $modules,
        public array $application,
        public array $sourceTemplate,
        public array $template,
        public string $descriptorSha256,
        public string $fromManifestPath,
        public string $toManifestPath,
        public string $releaseRoot,
        public string $releaseServerRoot,
        public string $targetLockPath,
    ) {}

    /** Verify staged application source plus its release and scaffold identities. */
    public static function load(string $projectRoot): self
    {
        $root = self::targetRoot($projectRoot);
        $descriptorPath = self::fixedFile($root, 'target.json');
        $descriptor = self::json($descriptorPath, 'UPGRADE_TARGET_DESCRIPTOR_INVALID');
        self::exact($descriptor, ['schema_version', 'protocol', 'release', 'scaffold', 'migrations', 'modules']);
        if (($descriptor['schema_version'] ?? null) !== 1
            || ($descriptor['protocol'] ?? null) !== 'peanut.application-upgrade-target.v1') {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }

        $release = self::release($descriptor['release'] ?? null);
        $scaffold = self::scaffold($descriptor['scaffold'] ?? null);
        $migrations = self::migrations($descriptor['migrations'] ?? null);
        $modules = self::modules($descriptor['modules'] ?? null);
        $fromManifestPath = self::fixedFile($root, 'from/scaffold-manifest.json');
        $toManifestPath = self::fixedFile($root, 'to/scaffold-manifest.json');
        self::assertDigest($fromManifestPath, $scaffold['from_manifest_sha256']);
        self::assertDigest($toManifestPath, $scaffold['to_manifest_sha256']);
        $sourceScaffoldRelease = self::assertScaffoldVersion($fromManifestPath, $scaffold['from_version']);
        $targetScaffoldRelease = self::assertScaffoldVersion(
            $toManifestPath,
            $scaffold['to_version'],
        );
        if ($scaffold['from_version'] === $scaffold['to_version']
            && !hash_equals($scaffold['from_manifest_sha256'], $scaffold['to_manifest_sha256'])) {
            throw new RuntimeException('UPGRADE_TARGET_SCAFFOLD_INVALID');
        }

        $releaseRoot = self::fixedDirectory($root, 'release');
        self::assertRegularTree($releaseRoot);
        self::assertGitTree($releaseRoot, $release['tree']);
        $application = self::applicationManifest(
            self::fixedFile($releaseRoot, '.peanut/application-manifest.json'),
        );
        $releaseVersionsPath = self::fixedFile($releaseRoot, 'release-versions.json');
        try {
            $versions = ApplicationReleaseVersions::load($releaseVersionsPath);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_IDENTITY_INVALID', 0, $exception);
        }
        $metadata = self::releaseMetadata(
            self::fixedFile($releaseRoot, 'RELEASE_METADATA.json'),
        );
        $productRelease = $versions->releaseSequenceVersion();
        $metadataVersion = $metadata['release_version'];
        if ($release['key'] !== 'v' . $productRelease
            || $metadataVersion !== $productRelease
            || (($metadata['schema_version'] ?? null) === 2
                && ($metadata['source_product_version'] ?? null) !== $versions->sourceProductVersion())
            || $metadata['expected_tag'] !== $release['key']
            || !hash_equals($application['package_identity'], $metadata['application_identity'])
            || !self::sameScaffoldRelease($application['template'], $targetScaffoldRelease)
            || $versions->scaffoldTemplate() !== $targetScaffoldRelease['version']) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_IDENTITY_INVALID');
        }
        $releaseServerRoot = self::fixedDirectory($releaseRoot, 'server');
        $targetLockPath = self::fixedFile($releaseRoot, 'plugins.lock');
        self::assertDigest($targetLockPath, $modules['lock_sha256']);

        $descriptorDigest = hash_file('sha256', $descriptorPath);
        if (!is_string($descriptorDigest)) {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }

        return new self(
            $release,
            $scaffold,
            $migrations,
            $modules,
            [
                'slug' => $application['slug'],
                'package_identity' => $application['package_identity'],
                'application_version' => $application['application_version'],
                'product_release' => $productRelease,
            ],
            $sourceScaffoldRelease,
            $application['template'],
            $descriptorDigest,
            $fromManifestPath,
            $toManifestPath,
            $releaseRoot,
            $releaseServerRoot,
            $targetLockPath,
        );
    }

    /** @return array<string,string> */
    public function sourceMigrationMap(): array
    {
        return self::migrationMap($this->migrations['from']['files']);
    }

    /** @return array<string,string> */
    public function targetMigrationMap(): array
    {
        return self::migrationMap($this->migrations['to']['files']);
    }

    private static function targetRoot(string $projectRoot): string
    {
        $candidate = rtrim($projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::TARGET_DIRECTORY);
        if (is_link($candidate)) {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }
        if (!is_dir($candidate)) {
            throw new RuntimeException('UPGRADE_TARGET_NOT_STAGED');
        }
        $resolved = realpath($candidate);
        $project = realpath($projectRoot);
        if ($resolved === false || $project === false
            || !str_starts_with($resolved, $project . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }
        return $resolved;
    }

    private static function fixedFile(string $root, string $relative): string
    {
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($candidate) || is_link($candidate)) {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }
        $resolved = realpath($candidate);
        if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }
        return $resolved;
    }

    private static function fixedDirectory(string $root, string $relative): string
    {
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir($candidate) || is_link($candidate)) {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }
        $resolved = realpath($candidate);
        if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }
        return $resolved;
    }

    private static function assertRegularTree(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            $resolved = $entry->getRealPath();
            if ($resolved === false
                || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
        }
    }

    /** Rebuild the staged release's Git tree identity without trusting a checkout or shell command. */
    private static function assertGitTree(string $root, string $expected): void
    {
        $actual = self::gitTreeHash($root);
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
        }
    }

    /** Hash one directory using Git's byte ordering, file modes and binary tree entry format. */
    private static function gitTreeHash(string $directory): string
    {
        $names = scandir($directory);
        if ($names === false) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
        }
        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($path)) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            if (is_dir($path)) {
                $entries[] = [
                    'name' => $name,
                    'sort' => $name . '/',
                    'mode' => '40000',
                    'hash' => self::gitTreeHash($path),
                ];
                continue;
            }
            if (!is_file($path)) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            $stat = stat($path);
            if (!is_array($stat) || !is_int($stat['mode'] ?? null)) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            $entries[] = [
                'name' => $name,
                'sort' => $name . "\0",
                'mode' => (($stat['mode'] & 0100) !== 0) ? '100755' : '100644',
                'hash' => self::gitBlobHash($path),
            ];
        }
        usort(
            $entries,
            static fn(array $left, array $right): int => strcmp($left['sort'], $right['sort']),
        );
        $context = hash_init('sha1');
        $size = 0;
        $content = [];
        foreach ($entries as $entry) {
            $object = pack('H*', $entry['hash']);
            if (strlen($object) !== 20) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            $row = $entry['mode'] . ' ' . $entry['name'] . "\0" . $object;
            $size += strlen($row);
            $content[] = $row;
        }
        if (!hash_update($context, 'tree ' . $size . "\0")) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
        }
        foreach ($content as $row) {
            if (!hash_update($context, $row)) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
        }
        $hash = hash_final($context);
        if (preg_match(self::COMMIT, $hash) !== 1) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
        }
        return $hash;
    }

    /** Stream a regular file into Git's blob framing and reject failed or short reads. */
    private static function gitBlobHash(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
        }
        try {
            $stat = fstat($handle);
            $size = is_array($stat) ? ($stat['size'] ?? null) : null;
            if (!is_int($size) || $size < 0) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            $context = hash_init('sha1');
            if (!hash_update($context, 'blob ' . $size . "\0")) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            $read = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false || ($chunk === '' && !feof($handle))) {
                    throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
                }
                $read += strlen($chunk);
                if (!hash_update($context, $chunk)) {
                    throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
                }
            }
            if ($read !== $size) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            $hash = hash_final($context);
            if (preg_match(self::COMMIT, $hash) !== 1) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_TREE_INVALID');
            }
            return $hash;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private static function json(string $path, string $code): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException($code, 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($code);
        }
        return $decoded;
    }

    /** @return array{key:string,commit:string,tree:string,qualification:array<string,mixed>} */
    private static function release(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_IDENTITY_INVALID');
        }
        self::exact($value, ['key', 'commit', 'tree', 'qualification']);
        $qualification = $value['qualification'] ?? null;
        if (!is_array($qualification) || array_is_list($qualification)) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_IDENTITY_INVALID');
        }
        self::exact($qualification, [
            'status', 'candidate_commit', 'candidate_tree', 'groups_passed',
            'cleanup_residual_count', 'lease_released',
        ]);
        $key = $value['key'] ?? null;
        $commit = $value['commit'] ?? null;
        $tree = $value['tree'] ?? null;
        if (!is_string($key) || preg_match(self::RELEASE_KEY, $key) !== 1
            || !is_string($commit) || preg_match(self::COMMIT, $commit) !== 1
            || !is_string($tree) || preg_match(self::COMMIT, $tree) !== 1
            || ($qualification['status'] ?? null) !== 'passed'
            || ($qualification['candidate_commit'] ?? null) !== $commit
            || ($qualification['candidate_tree'] ?? null) !== $tree
            || !is_int($qualification['groups_passed'] ?? null)
            || $qualification['groups_passed'] < 7
            || ($qualification['cleanup_residual_count'] ?? null) !== 0
            || ($qualification['lease_released'] ?? null) !== true) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_IDENTITY_INVALID');
        }
        return ['key' => $key, 'commit' => $commit, 'tree' => $tree, 'qualification' => $qualification];
    }

    /** @return array{from_version:string,from_manifest_sha256:string,to_version:string,to_manifest_sha256:string} */
    private static function scaffold(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('UPGRADE_TARGET_SCAFFOLD_INVALID');
        }
        self::exact($value, ['from_version', 'from_manifest_sha256', 'to_version', 'to_manifest_sha256']);
        foreach (['from_version', 'to_version'] as $key) {
            if (!is_string($value[$key] ?? null) || preg_match(self::VERSION, $value[$key]) !== 1) {
                throw new RuntimeException('UPGRADE_TARGET_SCAFFOLD_INVALID');
            }
        }
        foreach (['from_manifest_sha256', 'to_manifest_sha256'] as $key) {
            if (!is_string($value[$key] ?? null) || preg_match(self::SHA256, $value[$key]) !== 1) {
                throw new RuntimeException('UPGRADE_TARGET_SCAFFOLD_INVALID');
            }
        }
        return $value;
    }

    /** @return array{from:array{inventory_sha256:string,files:list<array{migration_id:string,sha256:string}>},to:array{inventory_sha256:string,files:list<array{migration_id:string,sha256:string}>}} */
    private static function migrations(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('UPGRADE_TARGET_MIGRATION_INVENTORY_INVALID');
        }
        self::exact($value, ['from', 'to']);
        return [
            'from' => self::migrationInventory($value['from'] ?? null),
            'to' => self::migrationInventory($value['to'] ?? null),
        ];
    }

    /** @return array{inventory_sha256:string,files:list<array{migration_id:string,sha256:string}>} */
    private static function migrationInventory(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('UPGRADE_TARGET_MIGRATION_INVENTORY_INVALID');
        }
        self::exact($value, ['inventory_sha256', 'files']);
        if (!is_string($value['inventory_sha256'] ?? null)
            || preg_match(self::SHA256, $value['inventory_sha256']) !== 1
            || !is_array($value['files'] ?? null) || !array_is_list($value['files'])) {
            throw new RuntimeException('UPGRADE_TARGET_MIGRATION_INVENTORY_INVALID');
        }
        $previous = null;
        foreach ($value['files'] as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException('UPGRADE_TARGET_MIGRATION_INVENTORY_INVALID');
            }
            self::exact($entry, ['migration_id', 'sha256']);
            if (!is_string($entry['migration_id'] ?? null)
                || preg_match(self::MIGRATION, $entry['migration_id']) !== 1
                || !is_string($entry['sha256'] ?? null)
                || preg_match(self::SHA256, $entry['sha256']) !== 1
                || ($previous !== null && strcmp($previous, $entry['migration_id']) >= 0)) {
                throw new RuntimeException('UPGRADE_TARGET_MIGRATION_INVENTORY_INVALID');
            }
            $previous = $entry['migration_id'];
        }
        $map = self::migrationMap($value['files']);
        $actual = hash('sha256', (string) json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (!hash_equals($value['inventory_sha256'], $actual)) {
            throw new RuntimeException('UPGRADE_TARGET_MIGRATION_INVENTORY_INVALID');
        }
        return $value;
    }

    /** @return array{lock_sha256:string,kernel_version:string} */
    private static function modules(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('UPGRADE_TARGET_MODULE_LOCK_INVALID');
        }
        self::exact($value, ['lock_sha256', 'kernel_version']);
        if (!is_string($value['lock_sha256'] ?? null)
            || preg_match(self::SHA256, $value['lock_sha256']) !== 1
            || !is_string($value['kernel_version'] ?? null)
            || preg_match(self::KERNEL_VERSION, $value['kernel_version']) !== 1) {
            throw new RuntimeException('UPGRADE_TARGET_MODULE_LOCK_INVALID');
        }
        return $value;
    }

    private static function assertDigest(string $path, string $expected): void
    {
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException('UPGRADE_TARGET_ARTIFACT_MISMATCH');
        }
    }

    /** @return array{version:string,source_commit:string,source_tree:string,inventory_sha256:string} */
    private static function assertScaffoldVersion(string $path, string $expected): array
    {
        $manifest = self::json($path, 'UPGRADE_TARGET_SCAFFOLD_INVALID');
        $release = is_array($manifest['release'] ?? null) ? $manifest['release'] : [];
        if (($release['version'] ?? null) !== $expected
            || !is_string($release['source_commit'] ?? null)
            || preg_match(self::COMMIT, $release['source_commit']) !== 1
            || !is_string($release['source_tree'] ?? null)
            || preg_match(self::COMMIT, $release['source_tree']) !== 1
            || !is_string($release['inventory_sha256'] ?? null)
            || preg_match(self::SHA256, $release['inventory_sha256']) !== 1) {
            throw new RuntimeException('UPGRADE_TARGET_SCAFFOLD_INVALID');
        }
        return [
            'version' => $expected,
            'source_commit' => $release['source_commit'],
            'source_tree' => $release['source_tree'],
            'inventory_sha256' => $release['inventory_sha256'],
        ];
    }

    /** @return array{slug:string,package_identity:string,application_version:string,template:array{version:string,source_commit:string,source_tree:string,inventory_sha256:string}} */
    private static function applicationManifest(string $path): array
    {
        $manifest = self::json($path, 'UPGRADE_TARGET_APPLICATION_MANIFEST_INVALID');
        $application = is_array($manifest['application'] ?? null) ? $manifest['application'] : [];
        $template = is_array($manifest['template'] ?? null) ? $manifest['template'] : [];
        $slug = $application['slug'] ?? null;
        $packageIdentity = $application['package_identity'] ?? null;
        $applicationVersion = $application['version'] ?? null;
        if (($manifest['schema_version'] ?? null) !== 2
            || ($manifest['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
            || !is_string($slug) || strlen($slug) > 63 || preg_match(self::SLUG, $slug) !== 1
            || !is_string($packageIdentity) || strlen($packageIdentity) > 120
            || preg_match(self::PACKAGE_IDENTITY, $packageIdentity) !== 1
            || !is_string($applicationVersion) || preg_match(self::APPLICATION_VERSION, $applicationVersion) !== 1
            || !is_string($template['version'] ?? null)
            || preg_match(self::VERSION, $template['version']) !== 1
            || !is_string($template['source_commit'] ?? null)
            || preg_match(self::COMMIT, $template['source_commit']) !== 1
            || !is_string($template['source_tree'] ?? null)
            || preg_match(self::COMMIT, $template['source_tree']) !== 1
            || !is_string($template['inventory_sha256'] ?? null)
            || preg_match(self::SHA256, $template['inventory_sha256']) !== 1) {
            throw new RuntimeException('UPGRADE_TARGET_APPLICATION_MANIFEST_INVALID');
        }
        return [
            'slug' => $slug,
            'package_identity' => $packageIdentity,
            'application_version' => $applicationVersion,
            'template' => [
                'version' => $template['version'],
                'source_commit' => $template['source_commit'],
                'source_tree' => $template['source_tree'],
                'inventory_sha256' => $template['inventory_sha256'],
            ],
        ];
    }

    /** @return array{application_identity:string,release_version:string,source_product_version:?string,expected_tag:string,schema_version:int,protocol:?string} */
    private static function releaseMetadata(string $path): array
    {
        $metadata = self::json($path, 'UPGRADE_TARGET_RELEASE_IDENTITY_INVALID');
        foreach (['application_identity', 'expected_tag'] as $key) {
            if (!is_string($metadata[$key] ?? null)) {
                throw new RuntimeException('UPGRADE_TARGET_RELEASE_IDENTITY_INVALID');
            }
        }
        $v2 = ($metadata['schema_version'] ?? null) === 2
            && ($metadata['protocol'] ?? null) === 'peanut.release-metadata.v2';
        $releaseVersion = $v2
            ? ($metadata['instance_version'] ?? $metadata['source_product_version'] ?? null)
            : ($metadata['version'] ?? null);
        $sourceProductVersion = $v2 ? ($metadata['source_product_version'] ?? null) : null;
        if (strlen($metadata['application_identity']) > 120
            || preg_match(self::PACKAGE_IDENTITY, $metadata['application_identity']) !== 1
            || !is_string($releaseVersion) || preg_match(self::VERSION, $releaseVersion) !== 1
            || ($v2 && (!is_string($sourceProductVersion)
                || preg_match(self::VERSION, $sourceProductVersion) !== 1))
            || preg_match(self::RELEASE_KEY, $metadata['expected_tag']) !== 1) {
            throw new RuntimeException('UPGRADE_TARGET_RELEASE_IDENTITY_INVALID');
        }
        return [
            'application_identity' => $metadata['application_identity'],
            'release_version' => $releaseVersion,
            'source_product_version' => $sourceProductVersion,
            'expected_tag' => $metadata['expected_tag'],
            'schema_version' => $v2 ? 2 : 1,
            'protocol' => $v2 ? 'peanut.release-metadata.v2' : null,
        ];
    }

    /** @param array<string,string> $left @param array<string,string> $right */
    private static function sameScaffoldRelease(array $left, array $right): bool
    {
        foreach (['version', 'source_commit', 'source_tree', 'inventory_sha256'] as $key) {
            if (!isset($left[$key], $right[$key]) || !hash_equals($left[$key], $right[$key])) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array{migration_id:string,sha256:string}> $files @return array<string,string> */
    private static function migrationMap(array $files): array
    {
        $map = [];
        foreach ($files as $entry) {
            $map[$entry['migration_id']] = $entry['sha256'];
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /** @param list<string> $keys */
    private static function exact(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new RuntimeException('UPGRADE_TARGET_DESCRIPTOR_INVALID');
        }
    }
}
