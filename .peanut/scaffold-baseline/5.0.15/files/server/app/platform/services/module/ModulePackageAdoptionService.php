<?php

declare(strict_types=1);

namespace app\platform\services\module;

use app\platform\exception\module\ModulePackageException;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\infrastructure\module\ModulePackageSourcePromoter;

/** Adopts a reviewed digest-pinned private package into development source for the next complete Application Release. */
final readonly class ModulePackageAdoptionService
{
    /** @param array<string,string> $trustedPublicKeys raw Ed25519 keys from the application's trust configuration */
    public function __construct(private string $serverRoot, private array $trustedPublicKeys, private string $environment) {}

    /** Recover a journal without trusting another archive and without touching Tenant/RBAC or migrations. */
    public function recover(): array
    {
        $this->development();
        $promoter = new ModulePackageSourcePromoter($this->serverRoot);
        return $promoter->run(fn(): array => $promoter->recover());
    }

    /** Verify the transport digest and any explicitly selected signature before a source-only transaction. */
    public function adopt(string $archivePath, ?string $expectedSha256, ?string $signatureKeyId): array
    {
        $this->development();
        if (!is_string($expectedSha256) || preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new ModulePackageException('MODULE_PACKAGE_SOURCE_UNTRUSTED', 'Adoption requires the reviewed archive SHA-256.');
        }
        if ($signatureKeyId !== null
            && ($signatureKeyId === '' || !isset($this->trustedPublicKeys[$signatureKeyId]))) {
            throw new ModulePackageException('MODULE_PACKAGE_SOURCE_UNTRUSTED', 'The explicitly selected signature key must be trusted.');
        }
        $promoter = new ModulePackageSourcePromoter($this->serverRoot);
        return $promoter->run(function () use ($promoter, $archivePath, $expectedSha256, $signatureKeyId): array {
            $recovery = $promoter->recover();
            $lockPath = dirname($this->serverRoot) . '/modules.lock';
            $current = is_file($lockPath) ? (new ModuleLockResolver($this->serverRoot, $lockPath))->all() : [];
            $versions = [];
            foreach ($current as $descriptor) {
                foreach ($descriptor->trust['compatibility']['modules'] as $module) {
                    $versions[$module['key']] = $module['version'];
                }
            }
            $archive = new ModulePackageArchiveService($this->serverRoot);
            $package = $archive->verify($archivePath, $expectedSha256, $this->trustedPublicKeys, $signatureKeyId, $versions);
            try {
                foreach ($package->modules as $module) {
                    \app\common\module\PackageCheck::assertEdition($module['manifest']->root, (string) \think\facade\Config::get('deployment.mode', ''));
                }
                foreach ([$package->packageKey, ...array_keys($package->modules)] as $key) {
                    if (str_starts_with($key, 'peanut.') || str_starts_with($key, 'official.')) {
                        throw new ModulePackageException('MODULE_PACKAGE_PRIVATE_REQUIRED', 'Adoption accepts private packages and Modules only.');
                    }
                }
                foreach ($current as $key => $descriptor) {
                    if ($key !== $package->packageKey && array_intersect(array_keys($descriptor->moduleRoots), array_keys($package->modules)) !== []) {
                        throw new ModulePackageException('PLUGIN_MODULE_CONFLICT', 'Module is already owned by another locked package.');
                    }
                }
                $previous = $current[$package->packageKey] ?? null;
                if ($previous !== null) {
                    $before = array_keys($previous->moduleRoots);
                    $after = array_keys($package->modules);
                    sort($before);
                    sort($after);
                    if ($before !== $after) {
                        throw new ModulePackageException('PLUGIN_UPDATE_SCOPE_CHANGED', 'Adoption cannot change the installed Module identity.');
                    }
                    $comparison = version_compare($package->packageVersion, $previous->version);
                    if ($comparison < 0) {
                        throw new ModulePackageException('PLUGIN_DOWNGRADE_REJECTED', 'Adoption cannot downgrade a package.');
                    }
                    if ($comparison === 0 && !hash_equals($previous->manifestDigest, $package->descriptor->manifestDigest)) {
                        throw new ModulePackageException('PACKAGE_VERSION_IDENTITY_CONFLICT', 'Same-version package identity changed.');
                    }
                }
                $routes = [];
                $declarations = [];
                foreach ($package->modules as $module) {
                    $relative = $module['backend_relative'] . '/route/app.php';
                    if (isset($package->inventory[$relative])) {
                        $routes[] = $relative;
                    }
                    $data = $module['manifest']->data;
                    $composer = json_decode((string) file_get_contents($package->stageRoot . '/' . $module['backend_relative'] . '/composer.json'), true, 64, JSON_THROW_ON_ERROR);
                    $clients = [];
                    foreach ($module['frontend_contributions'] as $contribution) {
                        $frontend = json_decode((string) file_get_contents($package->stageRoot . '/' . $contribution['root'] . '/package.json'), true, 64, JSON_THROW_ON_ERROR);
                        $clients[$contribution['client_key']] = array_intersect_key($frontend, array_flip(['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies', 'peerDependenciesMeta']));
                    }
                    $declarations[$module['key']] = [
                        'module' => $data['dependencies'] ?? [], 'optional_modules' => $data['optional_dependencies'] ?? [],
                        'tenant' => $data['tenant']['requires'] ?? [], 'composer' => $composer['require'] ?? [],
                        'composer_dev' => $composer['require-dev'] ?? [], 'frontend' => $clients,
                    ];
                }
                sort($routes, SORT_STRING);
                $next = is_file($lockPath) ? json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR) : ['schema_version' => 1, 'modules' => []];
                $descriptor = $package->descriptor;
                $incoming = ['key' => $descriptor->key, 'version' => $descriptor->version,
                    'source' => $descriptor->source, 'trust' => $descriptor->trust,
                    'composer' => $descriptor->composer, 'npm' => $descriptor->npm, 'frontend' => $descriptor->frontend,
                    'modules' => [['key' => $descriptor->key, 'root' => dirname($package->manifestRelative)]],
                    'manifest' => $package->manifestRelative, 'manifest_sha256' => $descriptor->manifestDigest];
                $entries = [];
                foreach ($next['modules'] as $entry) {
                    $entries[$entry['key']] = $entry;
                }
                $entries[$package->packageKey] = $incoming;
                ksort($entries, SORT_STRING);
                $next['modules'] = array_values($entries);
                $unchanged = $previous !== null && $previous->version === $package->packageVersion;
                if (!$unchanged) {
                    $promoter->promote($package, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", $previous !== null);
                }
                return ['status' => 'development-adopted', 'operation' => $unchanged ? 'unchanged' : 'adopted',
                    'package_key' => $package->packageKey, 'version' => $package->packageVersion,
                    'archive_sha256' => $package->archiveSha256, 'signature_key_id' => $signatureKeyId,
                    'route_contributions' => $routes, 'application_route_registration_required' => $routes !== [],
                    'dependencies_installed' => false,
                    'source_declarations' => $declarations,
                    'pending_actions' => ['native_dependency_install', 'registry_compile', 'module_migrations', 'database_install_registration', 'catalog_sync', 'frontend_and_process_adoption'],
                    'runtime_database_mutated' => false,
                    'tenant_modules_enabled' => false, 'rbac_granted' => false, 'recovery' => $recovery];
            } finally {
                $archive->cleanup($package);
            }
        });
    }

    /** The service boundary rejects source mutation from every non-development caller. */
    private function development(): void
    {
        if ($this->environment !== 'development') {
            throw new ModulePackageException('MODULE_SOURCE_ADOPTION_DISABLED', 'Source adoption requires development mode.');
        }
    }
}
