<?php

declare(strict_types=1);

namespace app\common\value\installation;

use JsonException;
use RuntimeException;
use think\App;
use think\Container;

/** Native backend identity carried by an assembled APP or server-only release. */
final readonly class ServerReleaseIdentity
{
    private function __construct(private array $document, private string $digest) {}

    /** Only a deployment-owner admission record may supply this already verified declaration. */
    public static function admitted(\app\common\infrastructure\installation\VerifiedServerDeployment $deployment): self
    {
        return new self($deployment->identityDocument(), $deployment->identityDigest());
    }

    /** @return array<string,mixed> Complete declaration, verified by load(), for closed-traffic admission. */
    public function document(): array
    {
        return $this->document;
    }

    /** Reuse current App admission or an explicitly verified CLI instance; other roots remain fresh. */
    public static function resolve(string $serverRoot): self
    {
        $container = Container::getInstance();
        $root = realpath($serverRoot);
        if ($container instanceof App && $root !== false && $root === realpath($container->getRootPath())
            && ($container->exists(self::class) || !$container->runningInConsole())) {
            return $container->make(self::class);
        }

        return self::load($serverRoot);
    }

    public static function load(string $serverRoot): self
    {
        $path = $serverRoot . '/.peanut/release-identity.json';
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        $bytes = file_get_contents($path);
        try {
            $value = is_string($bytes) ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID', 0, $exception);
        }
        if (!is_array($value) || ($value['schema_version'] ?? null) !== 1
            || ($value['protocol'] ?? null) !== 'peanut.server-release.v1'
            || !is_array($value['application'] ?? null)
            || !is_array($value['upstream'] ?? null)
            || !is_array($value['versions'] ?? null)
            || !is_array($value['files'] ?? null)) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        $app = $value['application'];
        $hex40 = '/^[a-f0-9]{40}$/D';
        $hex64 = '/^[a-f0-9]{64}$/D';
        $kind = $app['kind'] ?? null;
        if (!in_array($kind, ['application', 'generated-template', 'internal-candidate'], true)
            || preg_match($hex64, (string) ($app['manifest_sha256'] ?? '')) !== 1
            || ($kind === 'application' && (preg_match($hex40, (string) ($app['commit'] ?? '')) !== 1
                || preg_match($hex40, (string) ($app['tree'] ?? '')) !== 1))
            || (in_array($kind, ['generated-template', 'internal-candidate'], true) && (!array_key_exists('commit', $app)
                || !array_key_exists('tree', $app)
                || $app['commit'] !== null || $app['tree'] !== null))
            || !is_string($app['slug'] ?? null) || $app['slug'] === ''
            || !in_array($app['edition'] ?? null, ['standalone', 'multi-tenant'], true)
            || !is_string($app['version'] ?? null) || $app['version'] === ''
            || !in_array($app['profile'] ?? null, ['minimal', 'standard', 'full'], true)
            || !is_string($app['package_identity'] ?? null) || $app['package_identity'] === ''
            || !is_string($app['name'] ?? null) || $app['name'] === ''
            || preg_match($hex64, (string) ($app['source_files_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        $edition = $value['edition_contract'] ?? null;
        if (!is_array($edition)
            || ($edition['name'] ?? null) !== $app['edition']
            || ($edition['deployment_mode'] ?? null) !== $app['edition']
            || !is_array($edition['tenant_bootstrap'] ?? null)
            || preg_match($hex64, (string) ($value['edition_profile_sha256'] ?? '')) !== 1
            || !hash_equals((string) ($edition['source_sha256'] ?? ''), $value['edition_profile_sha256'])) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        foreach (['commit', 'tree'] as $key) {
            if (preg_match($hex40, (string) ($value['upstream'][$key] ?? '')) !== 1) {
                throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
            }
        }
        if (preg_match($hex64, (string) ($value['upstream']['inventory_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        $template = $value['template'] ?? null;
        if (!is_array($template)
            || preg_match($hex40, (string) ($template['source_commit'] ?? '')) !== 1
            || preg_match($hex40, (string) ($template['source_tree'] ?? '')) !== 1
            || preg_match($hex64, (string) ($template['inventory_sha256'] ?? '')) !== 1
            || !is_string($template['version'] ?? null) || $template['version'] === '') {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        foreach (['source_product_version', 'release_sequence_version', 'scaffold_template'] as $key) {
            if (!is_string($value['versions'][$key] ?? null) || $value['versions'][$key] === '') {
                throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
            }
        }
        $files = $value['files'];
        $canonical = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($files === [] || !hash_equals((string) ($value['files_sha256'] ?? ''), hash('sha256', $canonical))) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        $previous = '';
        foreach ($files as $file) {
            if (!is_array($file) || array_keys($file) !== ['path', 'sha256', 'mode']
                || !is_string($file['path']) || !str_starts_with($file['path'], 'server/')
                || $file['path'] <= $previous
                || preg_match('/[\x00-\x1f\\\\]/', $file['path']) === 1
                || in_array('', explode('/', $file['path']), true)
                || in_array('.', explode('/', $file['path']), true)
                || in_array('..', explode('/', $file['path']), true)
                || preg_match($hex64, (string) $file['sha256']) !== 1
                || !in_array($file['mode'], [0644, 0755], true)) {
                throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
            }
            $previous = $file['path'];
            $relative = substr($file['path'], strlen('server/'));
            $candidate = $serverRoot;
            foreach (explode('/', $relative) as $part) {
                $candidate .= '/' . $part;
                if (is_link($candidate)) {
                    throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
                }
            }
            if (!is_file($candidate) || !hash_equals($file['sha256'], (string) hash_file('sha256', $candidate))) {
                throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
            }
        }
        $listed = array_column($files, 'sha256', 'path');
        $listedModes = array_column($files, 'mode', 'path');
        foreach (['server/database/install.php', 'server/plugins.lock',
            'server/.peanut/RELEASE_METADATA.json',
            'server/resources/project-resources.json'] as $required) {
            if (!isset($listed[$required])) {
                throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
            }
        }
        $candidatePath = 'server/.peanut/internal-candidate.json';
        if ($kind === 'internal-candidate') {
            if (($app['scope'] ?? null) !== 'internal-candidate' || ($app['formal_release_eligible'] ?? null) !== false
                || !isset($listed[$candidatePath], $listed['server/.peanut/release-versions.json'], $listed['server/composer.lock'])
                || ($app['candidate_marker'] ?? null) !== ['path' => '.peanut/internal-candidate.json', 'sha256' => $listed[$candidatePath]]) {
                throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID');
            }
            try {
                $marker = json_decode((string) file_get_contents($serverRoot . '/.peanut/internal-candidate.json'), true, 512, JSON_THROW_ON_ERROR);
                $versions = json_decode((string) file_get_contents($serverRoot . '/.peanut/release-versions.json'), true, 512, JSON_THROW_ON_ERROR);
                $composer = json_decode((string) file_get_contents($serverRoot . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID', 0, $exception);
            }
            if (!is_array($marker) || !is_array($versions) || !is_array($composer) || ($marker['schema_version'] ?? null) !== 1
                || ($marker['protocol'] ?? null) !== 'peanut.internal-edition-candidate.v1'
                || ($marker['scope'] ?? null) !== 'internal-candidate' || ($marker['formal_release_eligible'] ?? null) !== false
                || ($marker['original_manifest_sha256'] ?? null) !== $app['manifest_sha256']
                || !is_array($marker['source_files'] ?? null) || $marker['source_files'] === []
                || ($marker['source_files']['.peanut/application-manifest.json']['sha256'] ?? null) !== $app['manifest_sha256']
                || ($marker['source_files']['release-versions.json']['sha256'] ?? null) !== $listed['server/.peanut/release-versions.json']
                || !is_array($marker['projection'] ?? null) || ($marker['projection'] ?? null) !== ($app['projection'] ?? null)
                || preg_match($hex64, (string) ($marker['projection']['original_tree_sha256'] ?? '')) !== 1
                || preg_match($hex64, (string) ($marker['projection']['projected_tree_sha256'] ?? '')) !== 1
                || !is_array($marker['selection'] ?? null) || ($marker['selection'] ?? null) !== ($app['selection'] ?? null)
                || count($marker['selection']) !== 4
                || preg_match($hex64, (string) ($marker['core_inputs_sha256'] ?? '')) !== 1
                || ($marker['selection']['edition'] ?? null) !== $app['edition']
                || ($marker['selection']['profile'] ?? null) !== 'full' || ($marker['selection']['workflow'] ?? null) !== 'full'
                || ($marker['selection']['clients'] ?? null) !== ['Admin', 'Platform', 'PC SPA', 'UniApp H5']) {
                throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID');
            }
            foreach (['commit', 'tree', 'inventory_sha256'] as $field) {
                if (($marker['generation_source'][$field] ?? null) !== $value['upstream'][$field]) {
                    throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID');
                }
            }
            foreach ($marker['source_files'] as $path => $row) {
                if (!is_string($path) || !is_array($row) || preg_match($hex64, (string) ($row['sha256'] ?? '')) !== 1
                    || !in_array($row['mode'] ?? null, [0644, 0755], true)) {
                    throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID');
                }
                if (!str_starts_with($path, 'server/')) {
                    continue; // Whole-APP source and archives were verified before assembly.
                }
                $placeholder = preg_match('~^server/(?:runtime|public/(?:storage|uploads)|private/(?:storage|resources|installation)|docker/(?:mysql|secrets))/~D', $path) === 1
                    && in_array(basename($path), ['.gitkeep', '.gitignore'], true)
                    && $row['sha256'] === hash('sha256', '') && $row['mode'] === 0644;
                if (!$placeholder && (($listed[$path] ?? null) !== $row['sha256'] || ($listedModes[$path] ?? null) !== $row['mode'])) {
                    throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID');
                }
            }
            $core = $marker['core'] ?? null;
            $packages = is_array($composer['packages'] ?? null)
                ? array_values(array_filter($composer['packages'], static fn(mixed $package): bool => is_array($package) && ($package['name'] ?? null) === 'peanut-admin/core')) : [];
            if (!is_array($core) || ($core['package'] ?? null) !== 'peanut-admin/core'
                || !is_string($core['resolved_version'] ?? null) || $core['resolved_version'] === ''
                || preg_match($hex40, (string) ($core['source_reference'] ?? '')) !== 1
                || preg_match($hex64, (string) ($core['sha256'] ?? '')) !== 1
                || preg_match('~^packages/core-php/peanut-admin-core-[0-9A-Za-z.+_-]+\.zip$~D', (string) ($core['path_in_archive'] ?? '')) !== 1
                || count($packages) !== 1 || ($packages[0]['version'] ?? null) !== ($core['resolved_version'] ?? null)
                || ($packages[0]['source']['reference'] ?? null) !== $core['source_reference']
                || ($packages[0]['dist']['reference'] ?? null) !== $core['source_reference']
                || ($packages[0]['dist']['url'] ?? null) !== '../' . $core['path_in_archive']
                || ($versions['core_php']['source_reference'] ?? null) !== $core['source_reference']
                || preg_match($hex40, (string) ($marker['web_source_reference'] ?? '')) !== 1
                || ($versions['core_web']['source_reference'] ?? null) !== ($marker['web_source_reference'] ?? null)
                || !is_array($marker['core_web'] ?? null) || count($marker['core_web']) !== 6
                || array_diff(['@peanut-admin/client', '@peanut-admin/vue', '@peanut-admin/ui-vue',
                    '@peanut-admin/nuxt', '@peanut-admin/uniapp', '@peanut-admin/testing'], array_keys($marker['core_web'])) !== []) {
                throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID');
            }
            foreach ($marker['core_web'] as $package => $record) {
                $actual = $versions['core_web']['packages'][$package] ?? null;
                if (!is_array($record) || !is_array($actual) || count($actual) !== 3
                    || ($record['version'] ?? null) !== $core['resolved_version']
                    || preg_match($hex64, (string) ($record['sha256'] ?? '')) !== 1
                    || preg_match('~^packages/core-web/peanut-admin-' . preg_quote(substr($package, strlen('@peanut-admin/')), '~')
                        . '-[0-9A-Za-z.+_-]+\.tgz$~D', (string) ($record['archive'] ?? '')) !== 1
                    || ($actual['version'] ?? null) !== ($record['version'] ?? null)
                    || ($actual['archive'] ?? null) !== ($record['archive'] ?? null)
                    || ($actual['sha256'] ?? null) !== ($record['sha256'] ?? null)) {
                    throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID');
                }
            }
        } elseif (isset($listed[$candidatePath]) || ($app['scope'] ?? null) === 'internal-candidate'
            || ($app['formal_release_eligible'] ?? null) === false || isset($app['candidate_marker'])) {
            throw new RuntimeException('SERVER_RELEASE_CANDIDATE_INVALID');
        }
        $registry = json_decode((string) file_get_contents($serverRoot . '/resources/project-resources.json'), true);
        if (!is_array($registry) || ($registry['project_id'] ?? null) !== $app['slug']
            || ($registry['authority']['role'] ?? null) !== 'application') {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        $releaseMetadata = json_decode((string) file_get_contents($serverRoot . '/.peanut/RELEASE_METADATA.json'), true);
        if (!is_array($releaseMetadata)
            || ($releaseMetadata['source_product_version'] ?? null) !== $value['versions']['source_product_version']
            || ($releaseMetadata['instance_version'] ?? null) !== $app['version']
            || ($releaseMetadata['application_identity'] ?? null) !== $app['package_identity']) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        $projection = $value['plugin_projection'] ?? null;
        if (!is_array($projection)
            || preg_match($hex64, (string) ($projection['source_lock_sha256'] ?? '')) !== 1
            || !hash_equals((string) ($projection['projected_lock_sha256'] ?? ''), $listed['server/plugins.lock'])
            || !is_array($projection['plugins'] ?? null)) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        $lock = json_decode((string) file_get_contents($serverRoot . '/plugins.lock'), true);
        if (!is_array($lock) || ($lock['schema_version'] ?? null) !== 1
            || ($lock['protocol'] ?? null) !== 'peanut.server-plugin-lock.v1'
            || !is_array($lock['plugins'] ?? null)
            || count($lock['plugins']) !== count($projection['plugins'])) {
            throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
        }
        foreach ($projection['plugins'] as $index => $item) {
            $plugin = $lock['plugins'][$index] ?? null;
            if (!is_array($item) || !is_array($plugin)
                || ($item['key'] ?? null) !== ($plugin['key'] ?? null)
                || !hash_equals(
                    (string) ($item['projected_manifest_sha256'] ?? ''),
                    (string) ($plugin['manifest_sha256'] ?? ''),
                )
                || !hash_equals(
                    (string) ($item['projected_source_sha256'] ?? ''),
                    (string) ($plugin['source']['sha256'] ?? ''),
                )) {
                throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
            }
            foreach (['source_manifest_sha256', 'source_sha256',
                'projected_manifest_sha256', 'projected_source_sha256'] as $field) {
                if (preg_match($hex64, (string) ($item[$field] ?? '')) !== 1) {
                    throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
                }
            }
            $manifestPath = 'server/' . ($plugin['manifest'] ?? '');
            if (!isset($listed[$manifestPath])
                || !hash_equals($listed[$manifestPath], $item['projected_manifest_sha256'])) {
                throw new RuntimeException('SERVER_RELEASE_IDENTITY_INVALID');
            }
        }
        return new self($value, hash('sha256', $bytes));
    }

    /** @return array<string,mixed> */
    public function applicationIdentity(): array
    {
        return $this->document['application'];
    }

    /** @return array<string,mixed> */
    public function templateIdentity(): array
    {
        return $this->document['template'];
    }

    /** @return array{commit:string,tree:string} */
    public function runtimeSourceIdentity(): array
    {
        $application = $this->document['application'];
        if (($application['kind'] ?? null) === 'application') {
            return ['commit' => $application['commit'], 'tree' => $application['tree']];
        }
        return [
            'commit' => $this->document['upstream']['commit'],
            'tree' => $this->document['upstream']['tree'],
        ];
    }

    public function versions(): array
    {
        return $this->document['versions'];
    }

    public function manifestSha256(): string
    {
        return $this->document['application']['manifest_sha256'];
    }

    public function identitySha256(): string
    {
        return $this->digest;
    }

    public function tenantBootstrapContract(string $deploymentMode): array
    {
        if ($deploymentMode !== $this->document['application']['edition']) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_INVALID');
        }
        return $this->document['edition_contract']['tenant_bootstrap'];
    }

    public function projectedPluginLockSha256(): string
    {
        return $this->document['plugin_projection']['projected_lock_sha256'];
    }

    public function applicationSlug(): string
    {
        return $this->document['application']['slug'];
    }
}
