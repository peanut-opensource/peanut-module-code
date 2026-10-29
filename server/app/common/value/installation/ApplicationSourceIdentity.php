<?php

declare(strict_types=1);

namespace app\common\value\installation;

use JsonException;
use RuntimeException;

/** Strict identity reader for generated/source application layouts without a server release identity. */
final readonly class ApplicationSourceIdentity
{
    public const RUNTIME_PATH = '.peanut/development-runtime.json';
    /** @param array<string,mixed> $application @param array<string,mixed> $versions */
    private function __construct(
        private array $application,
        private array $versions,
        private array $tenantBootstrap,
        private ?array $runtime = null,
        private ?string $runtimeDigest = null,
    ) {}

    public static function hasRuntime(string $serverRoot): bool
    {
        return file_exists($serverRoot . '/' . self::RUNTIME_PATH) || is_link($serverRoot . '/' . self::RUNTIME_PATH);
    }

    /** Only the original owner admission supplies this immutable declaration to FPM. */
    public static function admitted(\app\common\infrastructure\installation\VerifiedServerDeployment $deployment): self
    {
        $value = $deployment->identityDocument();
        if (!in_array($value['protocol'] ?? null, ['peanut.development-server-runtime.v1', 'peanut.application-source-identity.v1'], true)) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
        }
        return new self($value['application'], $value['versions'], $value['tenant_bootstrap'], $value, $deployment->identityDigest());
    }

    public static function load(string $serverRoot): self
    {
        if (\app\common\infrastructure\installation\ReadonlyHttpMount::active($serverRoot)) {
            $app = \think\Container::getInstance();
            if ($app instanceof \think\App && !$app->runningInConsole()) {
                return self::admitted($app->make(\app\common\infrastructure\installation\VerifiedServerDeployment::class));
            }
        }
        if (self::hasRuntime($serverRoot)) {
            return self::loadRuntime($serverRoot);
        }
        return self::loadGeneratedApp($serverRoot);
    }

    /** Read the generated APP source identity independently of any runtime projection. */
    public static function loadGeneratedApp(string $serverRoot): self
    {
        $projectRoot = dirname($serverRoot);
        $manifest = self::jsonFile($projectRoot . '/.peanut/application-manifest.json', 'APPLICATION_SOURCE_MANIFEST_INVALID');
        $identity = self::fromManifest($manifest, ApplicationReleaseVersions::load($projectRoot . '/release-versions.json'));
        $document = ['protocol' => 'peanut.application-source-identity.v1',
            'application' => $identity->applicationIdentity(), 'versions' => $identity->versions(),
            'tenant_bootstrap' => $identity->tenantBootstrap,
            'application_manifest_sha256' => hash_file('sha256', $projectRoot . '/.peanut/application-manifest.json')];
        return new self($identity->application, $identity->versions, $identity->tenantBootstrap,
            $document, self::sourceDigest($serverRoot));
    }

    /** The two tracked APP declarations are mounted read-only beside the server in Docker. */
    public static function sourceDigest(string $serverRoot): string
    {
        $root = dirname($serverRoot);
        $digests = [];
        foreach (['.peanut/application-manifest.json', 'release-versions.json'] as $relative) {
            $path = $root . '/' . $relative;
            if (!is_file($path) || is_link($path)) {
                throw new RuntimeException('APPLICATION_SOURCE_MANIFEST_INVALID');
            }
            $digests[] = hash_file('sha256', $path);
        }
        return hash('sha256', implode(':', $digests));
    }

    private static function fromManifest(array $manifest, ApplicationReleaseVersions $versions): self
    {
        $application = $manifest['application'] ?? null;
        $edition = $manifest['edition'] ?? null;
        if (!is_array($application)
            || !is_array($edition)
            || ($manifest['schema_version'] ?? null) !== 2
            || ($manifest['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
            || !is_string($application['name'] ?? null)
            || trim($application['name']) === ''
            || !is_string($application['slug'] ?? null)
            || preg_match('/^[a-z][a-z0-9-]{0,62}[a-z0-9]$/D', $application['slug']) !== 1
            || !is_string($application['package_identity'] ?? null)
            || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $application['package_identity']) !== 1
            || !is_string($application['version'] ?? null)
            || !in_array($application['edition'] ?? null, ['standalone', 'multi-tenant'], true)
            || !in_array($application['profile'] ?? null, ['minimal', 'standard', 'full'], true)
            || ($edition['name'] ?? null) !== $application['edition']
            || ($edition['deployment_mode'] ?? null) !== $application['edition']
            || !is_array($edition['tenant_bootstrap'] ?? null)) {
            throw new RuntimeException('APPLICATION_SOURCE_IDENTITY_INVALID');
        }

        // Preserve the existing installer's binding between the selected Edition
        // and its immutable generation or accepted upgrade identity.
        $profileDigest = $edition['source_sha256'] ?? null;
        $expectedDigest = isset($manifest['last_scaffold_upgrade'])
            ? ($manifest['last_scaffold_upgrade']['edition_profile_sha256'] ?? null)
            : ($manifest['generation_source']['edition_profile_sha256'] ?? null);
        if (!is_string($profileDigest) || !is_string($expectedDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $profileDigest) !== 1
            || !hash_equals($profileDigest, $expectedDigest)) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_INVALID');
        }

        if (!hash_equals($application['version'], $versions->releaseSequenceVersion())) {
            throw new RuntimeException('APPLICATION_SOURCE_VERSION_MISMATCH');
        }

        return new self(
            [
                'slug' => $application['slug'],
                'edition' => $application['edition'],
                'version' => $application['version'],
                'profile' => $application['profile'],
                'package_identity' => $application['package_identity'],
                'name' => $application['name'],
            ],
            [
                'source_product_version' => $versions->sourceProductVersion(),
                'release_sequence_version' => $versions->releaseSequenceVersion(),
                'scaffold_template' => $versions->scaffoldTemplate(),
            ],
            $edition['tenant_bootstrap'],
        );
    }

    /** Fresh owner verification. Runtime and secret data never belong to this program inventory. */
    public static function loadRuntime(string $serverRoot): self
    {
        if (file_exists($serverRoot . '/.peanut/release-identity.json') || is_link($serverRoot . '/.peanut/release-identity.json')) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_CONFLICT');
        }
        $runtimePath = self::regular($serverRoot, self::RUNTIME_PATH);
        if ((fileperms($runtimePath) & 0777) !== 0644) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
        }
        $value = self::jsonFile($runtimePath, 'APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
        if (($value['protocol'] ?? null) !== 'peanut.development-server-runtime.v1'
            || ($value['schema_version'] ?? null) !== 1 || ($value['environment'] ?? null) !== 'development'
            || !is_array($value['files'] ?? null) || $value['files'] === []
            || !hash_equals((string) ($value['files_sha256'] ?? ''), hash('sha256', json_encode($value['files'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
        }
        $previous = '';
        $listed = [];
        foreach ($value['files'] as $file) {
            $relative = $file['path'] ?? '';
            if (!is_string($relative) || !str_starts_with($relative, 'server/') || $relative <= $previous
                || preg_match('/[\\\\\x00-\x1f]/', $relative) || array_intersect(['', '.', '..'], explode('/', $relative)) !== []
                || !in_array($file['mode'] ?? null, [0644, 0755], true)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($file['sha256'] ?? '')) !== 1
                || ($relative !== 'server/.env.example' && preg_match('#^server/(?:vendor/|runtime/|private/|public/(?:storage|uploads)/|docker/(?:mysql|secrets)/|\\.env(?:$|\\.)|\\.peanut/development-runtime\\.json$)#D', $relative))) {
                throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
            }
            $path = self::regular($serverRoot, substr($relative, 7));
            if (!hash_equals($file['sha256'], (string) hash_file('sha256', $path)) || (fileperms($path) & 0777) !== $file['mode']) {
                throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_CHANGED');
            }
            $previous = $relative;
            $listed[$relative] = $file['sha256'];
        }
        foreach (['server/.peanut/application-manifest.json', 'server/.peanut/release-versions.json',
            'server/plugins.lock', 'server/composer.json', 'server/composer.lock', 'server/resources/project-resources.json'] as $required) {
            if (!isset($listed[$required])) {
                throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
            }
        }
        $manifest = self::jsonFile($serverRoot . '/.peanut/application-manifest.json', 'APPLICATION_SOURCE_MANIFEST_INVALID');
        if (($manifest['protocol'] ?? null) !== 'peanut.application-scaffold.v2' || ($manifest['schema_version'] ?? null) !== 2
            || preg_match('/^[a-f0-9]{40}$/D', (string) ($manifest['generation_source']['commit'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', (string) ($manifest['generation_source']['tree'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['generation_source']['inventory_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
        }
        $versions = ApplicationReleaseVersions::load($serverRoot . '/.peanut/release-versions.json');
        $source = self::fromManifest($manifest, $versions);
        if ($value['application'] !== $source->applicationIdentity() || $value['versions'] !== $source->versions()
            || $value['tenant_bootstrap'] !== $source->tenantBootstrap || ($value['core_php'] ?? null) !== $versions->toArray()['core_php']
            || ($value['application_manifest_sha256'] ?? null) !== $listed['server/.peanut/application-manifest.json']
            || ($value['plugin_projection']['projected_lock_sha256'] ?? null) !== $listed['server/plugins.lock']) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
        }
        $registry = self::jsonFile($serverRoot . '/resources/project-resources.json', 'APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
        if (($registry['project_id'] ?? null) !== $source->applicationSlug() || ($registry['authority']['role'] ?? null) !== 'application') {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
        }
        self::verifyDependencies($serverRoot, $value['core_php']);
        return new self(
            $source->application,
            $source->versions,
            $source->tenantBootstrap,
            $value,
            (string) hash_file('sha256', $serverRoot . '/' . self::RUNTIME_PATH),
        );
    }

    private static function verifyDependencies(string $root, array $core): void
    {
        $manifest = self::jsonFile(self::regular($root, 'composer.json'), 'APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
        $lock = self::jsonFile(self::regular($root, 'composer.lock'), 'APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
        $installed = self::jsonFile(self::regular($root, 'vendor/composer/installed.json'), 'APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
        $seal = self::jsonFile(self::regular($root, 'vendor/.peanut-complete.json'), 'APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
        if (($manifest['require']['peanut-admin/core'] ?? null) !== $core['constraint']
            || ($seal['protocol'] ?? null) !== 'peanut.composer-install.v1'
            || ($seal['composer_sha256'] ?? null) !== hash_file('sha256', $root . '/composer.json')
            || ($seal['lock_sha256'] ?? null) !== hash_file('sha256', $root . '/composer.lock') || !is_array($seal['files'] ?? null)) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
        }
        $expected = array_column($lock['packages'] ?? [], 'version', 'name');
        $actual = array_column($installed['packages'] ?? [], 'version', 'name');
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
        }
        foreach ([$lock['packages'] ?? [], $installed['packages'] ?? []] as $packages) {
            $matches = array_values(array_filter($packages, static fn(array $package): bool => $package['name'] === 'peanut-admin/core'));
            if (count($matches) !== 1 || $matches[0]['version'] !== $core['resolved_version']
                || ($matches[0]['source']['type'] ?? null) !== $core['source_type']
                || ($matches[0]['source']['url'] ?? null) !== $core['source_url']
                || ($matches[0]['source']['reference'] ?? null) !== $core['source_reference']) {
                throw new RuntimeException('APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
            }
        }
        $corePackages = array_values(array_filter($installed['packages'], static fn(array $package): bool => $package['name'] === 'peanut-admin/core'));
        $installPath = $corePackages[0]['install-path'] ?? null;
        $coreRoot = realpath($root . '/vendor/peanut-admin/core');
        if (!is_string($installPath) || !is_string($coreRoot) || !is_dir($coreRoot)
            || realpath($root . '/vendor/composer/' . $installPath) !== $coreRoot) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
        }
        foreach ($seal['files'] as $relative => $proof) {
            $path = self::regular($root, 'vendor/' . $relative);
            if (!is_array($proof) || count($proof) !== 2 || hash_file('sha256', $path) !== $proof[0] || (fileperms($path) & 0777) !== $proof[1]) {
                throw new RuntimeException('APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
            }
        }
        $actualFiles = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/vendor', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
            }
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($root . '/vendor/'));
                if ($relative !== '.peanut-complete.json') {
                    $actualFiles[] = $relative;
                }
            }
        }
        $expectedFiles = array_keys($seal['files']);
        sort($actualFiles);
        sort($expectedFiles);
        if ($actualFiles !== $expectedFiles) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_DEPENDENCIES_INVALID');
        }
    }

    private static function regular(string $root, string $relative): string
    {
        if (preg_match('/[\\\\\x00-\x1f]/', $relative) || str_starts_with($relative, '/') || array_intersect(['', '.', '..'], explode('/', $relative)) !== []) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_PATH_INVALID');
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_PATH_INVALID');
            }
        }
        $stat = @lstat($path);
        if (!is_file($path) || !is_array($stat) || $stat['nlink'] !== 1) {
            throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_PATH_INVALID');
        }
        return $path;
    }

    public function document(): array
    {
        return $this->runtime ?? throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_REQUIRED');
    }

    public function identitySha256(): string
    {
        return $this->runtimeDigest ?? throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_REQUIRED');
    }

    public function manifestSha256(): string
    {
        return $this->document()['application_manifest_sha256'];
    }

    public function projectedPluginLockSha256(): string
    {
        return $this->document()['plugin_projection']['projected_lock_sha256'];
    }

    /** @return array<string,mixed> */
    public function applicationIdentity(): array
    {
        return $this->application;
    }

    /** @return array<string,mixed> */
    public function versions(): array
    {
        return $this->versions;
    }

    /** @return array<string,mixed> */
    public function tenantBootstrapContract(string $deploymentMode): array
    {
        if ($deploymentMode !== $this->application['edition']) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_INVALID');
        }
        return $this->tenantBootstrap;
    }

    public function applicationSlug(): string
    {
        return $this->application['slug'];
    }

    /** @return array<string,mixed> */
    private static function jsonFile(string $path, string $error): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException($error);
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException($error);
        }
        return $value;
    }
}
