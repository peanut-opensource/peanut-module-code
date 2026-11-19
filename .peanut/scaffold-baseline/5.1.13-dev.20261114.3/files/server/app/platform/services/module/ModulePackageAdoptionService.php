<?php

declare(strict_types=1);

namespace app\platform\services\module;

use app\platform\exception\module\ModulePackageException;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\infrastructure\module\ModulePackageSourcePromoter;
use app\common\module\PackageCheck;
use app\common\module\ModuleMigrationRun;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\StrictVersionConstraintMatcher;

/** Adopts a reviewed digest-pinned Module package into development source for subsequent Runtime reconciliation. */
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

    /** Edition-bound pre-install composition reuses the existing source writer and recovery journal. */
    public function compose(array $request, ?string $approvedPlanSha256 = null): array
    {
        $this->development();
        $promoter = new ModulePackageSourcePromoter($this->serverRoot);
        $operation = fn(): array => $this->compositionPlan($request, $promoter, $approvedPlanSha256);
        return $approvedPlanSha256 === null ? $operation() : $promoter->run($operation);
    }

    private function compositionPlan(array $request, ModulePackageSourcePromoter $promoter, ?string $approvedSha): array
    {
        $root = dirname($this->serverRoot);
        $edition = $request['edition'] ?? null;
        if (($request['schema_version'] ?? null) !== 1
            || ($request['protocol'] ?? null) !== 'peanut.module-source-composition-request.v1'
            || !in_array($edition, ['standalone', 'multi-tenant'], true)
            || (string) \think\facade\Config::get('deployment.mode', '') !== $edition) {
            throw new ModulePackageException('MODULE_COMPOSITION_REQUEST_INVALID', 'Composition requires the exact supported Edition of the uninstalled Host.');
        }
        foreach (['private/installation/installed.json', 'private/installation/executing.json',
            'runtime/installation/installed.json', 'runtime/installation/executing.json'] as $marker) {
            if (file_exists($this->serverRoot . '/' . $marker) || is_link($this->serverRoot . '/' . $marker)) {
                throw new ModulePackageException('MODULE_COMPOSITION_UNINSTALLED_REQUIRED', 'Use runtime lifecycle operations for an installed or installing application.');
            }
        }
        if ($promoter->recoveryState()['status'] !== 'clean') {
            throw new ModulePackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Resume the existing source journal before planning.');
        }
        require_once $this->serverRoot . '/database/InitialInstallationSource.php';
        $composition = \InitialInstallationSource::composition($this->serverRoot);
        $compositionSha = hash_file('sha256', $this->serverRoot . '/config/module_composition.json');
        if (($request['composition_sha256'] ?? null) !== $compositionSha
            || !in_array($request['preset'] ?? null, ['minimal', 'standard'], true)
            || !is_array($request['optional'] ?? null) || !array_is_list($request['optional'])
            || array_filter($request['optional'], static fn(mixed $key): bool => !is_string($key)) !== []
            || array_diff($request['optional'], $composition['optional']) !== []) {
            throw new ModulePackageException('MODULE_COMPOSITION_SELECTION_INVALID', 'Use the exact host composition and explicitly declared optional roots.');
        }
        $roots = array_values(array_unique([...$composition['mandatory'],
            ...($request['preset'] === 'standard' ? $composition['default'] : []), ...$request['optional']]));
        sort($roots, SORT_STRING);
        $identity = $request['index'] ?? [];
        $indexPath = $identity['path'] ?? null;
        if (!is_string($indexPath) || !str_starts_with($indexPath, '/') || !is_file($indexPath)
            || !is_string($identity['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $identity['sha256']) !== 1
            || hash_file('sha256', $indexPath) !== $identity['sha256']) {
            throw new ModulePackageException('MODULE_COMPOSITION_INDEX_INVALID', 'Provide one independently pinned index file.');
        }
        $index = json_decode((string) file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
        $indexVersion = $index['schema_version'] ?? null;
        if (!in_array($indexVersion, [1, 2], true)
            || ($index['protocol'] ?? null) !== 'peanut.module-release-index.v' . $indexVersion
            || ($index['repository'] ?? null) !== 'peanut-opensource/peanut-module-code'
            || ($index['repository'] ?? null) !== ($identity['repository'] ?? null)
            || !is_string($index['tag'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $index['tag']) !== 1
            || $index['tag'] !== ($identity['tag'] ?? null)
            || !is_string($index['commit'] ?? null) || preg_match('/^[a-f0-9]{40}$/D', $index['commit']) !== 1
            || $index['commit'] !== ($identity['commit'] ?? null)
            || !is_array($index['modules'] ?? null) || !array_is_list($index['modules']) || $index['modules'] === [] || count($index['modules']) > 100) {
            throw new ModulePackageException('MODULE_COMPOSITION_INDEX_INVALID', 'Release index identity differs from its pin.');
        }
        $catalog = [];
        foreach ($index['modules'] as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null)
                || preg_match('/^peanut\.[a-z][a-z0-9-]*$/D', $row['key']) !== 1 || isset($catalog[$row['key']])
                || !is_string($row['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $row['version']) !== 1
                || !is_string($row['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $row['sha256']) !== 1
                || !is_array($row['dependencies'] ?? null) || !array_is_list($row['dependencies'])
                || !is_array($row['editions'] ?? null) || !array_is_list($row['editions'])
                || $row['editions'] === [] || array_diff($row['editions'], ['standalone', 'multi-tenant']) !== []
                || count(array_unique($row['editions'])) !== count($row['editions'])
                || !is_string($row['kernel_constraint'] ?? null) || !is_int($row['bytes'] ?? null) || $row['bytes'] < 1
                || ($row['asset'] ?? null) !== $row['key'] . '-' . $row['version'] . '.tar'
                || ($row['license'] ?? null) !== 'Apache-2.0') {
                throw new ModulePackageException('MODULE_COMPOSITION_INDEX_INVALID', 'Invalid or duplicate catalog entry.');
            }
            if ($indexVersion === 2) {
                if (!is_string($row['artifact_tag'] ?? null)
                    || preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.(0|[1-9][0-9]*))?$/D', $row['artifact_tag']) !== 1
                    || !is_string($row['source_sha256'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $row['source_sha256']) !== 1) {
                    throw new ModulePackageException('MODULE_COMPOSITION_INDEX_INVALID', 'Incremental catalog reference is invalid.');
                }
            } elseif (array_key_exists('artifact_tag', $row)) {
                throw new ModulePackageException('MODULE_COMPOSITION_INDEX_INVALID', 'Artifact references require the v2 catalog.');
            }
            foreach ($row['dependencies'] as $dependency) {
                if (!is_array($dependency) || !is_string($dependency['module_key'] ?? null)
                    || !is_string($dependency['version'] ?? null)) {
                    throw new ModulePackageException('MODULE_COMPOSITION_INDEX_INVALID', 'Invalid dependency declaration.');
                }
            }
            $catalog[$row['key']] = $row;
        }
        $lockPath = $root . '/modules.lock';
        $current = is_file($lockPath) ? (new ModuleLockResolver($this->serverRoot, $lockPath))->all() : [];
        $entries = is_file($lockPath) ? json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR)['modules'] : [];
        $entries = array_column($entries, null, 'key');
        $loader = new ManifestLoader();
        $graph = [];
        // Preserve every existing source and validate its reverse dependencies against the final graph.
        foreach ($current as $key => $descriptor) {
            $data = $loader->load($descriptor->moduleRoots[$key])->data;
            $graph[$key] = ['version' => $descriptor->version, 'dependencies' => $data['dependencies'] ?? []];
        }
        $pending = $roots;
        $selected = [];
        $diagnostics = [];
        while ($pending !== []) {
            $key = array_pop($pending);
            if (isset($selected[$key])) {
                continue;
            }
            $row = $catalog[$key] ?? null;
            if ($row === null) {
                $selected[$key] = true;
                $diagnostics[] = ['code' => 'MODULE_COMPOSITION_CANDIDATE_MISSING', 'module_key' => $key];
                continue;
            }
            $keep = isset($current[$key]) && version_compare($current[$key]->version, $row['version']) > 0;
            $selected[$key] = $keep ? 'retain-higher' : 'candidate';
            $graph[$key] = $keep ? $graph[$key] : ['version' => $row['version'], 'dependencies' => $row['dependencies']];
            foreach ($graph[$key]['dependencies'] as $dependency) {
                $pending[] = $dependency['module_key'];
            }
        }
        ksort($graph, SORT_STRING);
        $preflight = new PackageCheck($root);
        $diagnostics = [...$diagnostics, ...$preflight->dependencyDiagnostics($graph)];
        $moduleConfig = (static fn(string $path): array => require $path)($this->serverRoot . '/config/modules.php');
        $kernel = (string) $moduleConfig['kernel_version'];
        $matcher = new StrictVersionConstraintMatcher();
        $archive = new ModulePackageArchiveService($this->serverRoot);
        $packages = [];
        $modules = [];
        $manifests = [];
        $writes = [];
        $versions = array_map(static fn(array $module): string => $module['version'], $graph);
        try {
            foreach ($graph as $key => $definition) {
                try {
                    $candidate = ($selected[$key] ?? null) === 'candidate';
                    if ($candidate) {
                        $row = $catalog[$key];
                        $path = $request['archives'][$key] ?? null;
                        if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path) || filesize($path) !== $row['bytes']) {
                            throw new ModulePackageException('MODULE_COMPOSITION_ARCHIVE_REQUIRED', 'Provide the exact verified candidate TAR.');
                        }
                        $package = $archive->verify($path, $row['sha256'], [], null, $versions, true);
                        $packages[$key] = $package;
                        $inspection = $package->modules[$key] ?? null;
                        if ($package->packageKey !== $key || $package->packageVersion !== $row['version'] || $inspection === null
                            || $inspection['dependencies'] != $row['dependencies'] || $inspection['manifest']->data['kernel_constraint'] !== $row['kernel_constraint']
                            || $inspection['manifest']->data['license'] !== $row['license']
                            || array_diff($row['editions'], $inspection['editions']) !== [] || array_diff($inspection['editions'], $row['editions']) !== []) {
                            throw new ModulePackageException('MODULE_COMPOSITION_ARCHIVE_MISMATCH', 'Archive differs from the pinned catalog.');
                        }
                        if (($package->descriptor->source['type'] ?? null) !== 'canonical-contents'
                            || ($package->descriptor->trust['origin']['type'] ?? null) !== 'repository-contents'
                            || ($package->descriptor->trust['origin']['reference'] ?? null) !== 'canonical-plugin-contents-v1') {
                            throw new ModulePackageException('MODULE_PACKAGE_OFFICIAL_SOURCE_UNTRUSTED', 'Official source must have canonical identity.');
                        }
                        $previous = $current[$key] ?? null;
                        $same = $previous !== null && $previous->version === $package->packageVersion;
                        if ($same && ($previous->manifestDigest !== $package->descriptor->manifestDigest
                            || $previous->source['sha256'] !== $package->descriptor->source['sha256']
                            || $promoter->pinnedArchiveSha($key, $previous->version, $previous->source['sha256']) !== $row['sha256'])) {
                            throw new ModulePackageException('PACKAGE_VERSION_IDENTITY_CONFLICT', 'Same version has different or unverified archive bytes.');
                        }
                        $action = $same ? 'unchanged' : ($previous === null ? 'add' : 'upgrade');
                        if (!$same) {
                            $descriptor = $package->descriptor;
                            $entries[$key] = ['key' => $key, 'version' => $descriptor->version, 'source' => $descriptor->source,
                                'trust' => $descriptor->trust, 'composer' => $descriptor->composer, 'npm' => $descriptor->npm,
                                'frontend' => $descriptor->frontend, 'modules' => [['key' => $key, 'root' => dirname($package->manifestRelative)]],
                                'manifest' => $package->manifestRelative, 'manifest_sha256' => $descriptor->manifestDigest];
                            foreach ($package->inventory as $file => $sha) {
                                $writes[$file] = $sha;
                            }
                        }
                        $manifest = $inspection['manifest'];
                        $archiveSha = $row['sha256'];
                    } else {
                        $manifest = $loader->load($current[$key]->moduleRoots[$key]);
                        $action = ($selected[$key] ?? null) === 'retain-higher' ? 'retain-higher' : 'retain';
                        $archiveSha = $promoter->pinnedArchiveSha($key, $current[$key]->version, $current[$key]->source['sha256']);
                    }
                    PackageCheck::assertEdition($manifest->root, $edition);
                    $composer = json_decode((string) file_get_contents($manifest->root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                    PackageCheck::assertComposerRequirements(array_filter(
                        $composer['require'] ?? [],
                        static fn(string $name): bool => in_array($name, ['php', 'peanut-admin/core'], true) || str_starts_with($name, 'ext-'),
                        ARRAY_FILTER_USE_KEY,
                    ));
                    if (!$matcher->matches($kernel, $manifest->data['kernel_constraint'])) {
                        throw new ModulePackageException('MODULE_KERNEL_INCOMPATIBLE', 'Module Kernel requirement is incompatible.');
                    }
                    $requirement = PackageCheck::compositionHostRequirement($manifest->root);
                    if ($requirement !== null) {
                        PackageCheck::assertCompositionHost($key, $requirement, PackageCheck::compositionHostVersion((string) file_get_contents($this->serverRoot . '/composer.json')));
                    }
                    $manifests[$key] = $manifest;
                    $modules[$key] = ['key' => $key, 'version' => $definition['version'], 'action' => $action,
                        'archive_sha256' => $archiveSha, 'source_sha256' => $entries[$key]['source']['sha256'],
                        'dependencies' => $definition['dependencies'], 'composer_requirements' => $composer['require'] ?? [],
                        'frontend' => $manifest->data['frontend'] ?? [], 'installation' => 'uninstalled', 'activation' => 'not-run'];
                } catch (\app\platform\exception\module\ModuleLifecycleException|ModulePackageException $exception) {
                    $diagnostics[] = ['code' => $exception->errorCode, 'module_key' => $key, 'message' => $exception->getMessage()];
                }
            }
            $migrations = [];
            try {
                \app\common\infrastructure\module\ModuleHostLayoutFactory::fromModuleRoots(
                    array_map(static fn($manifest): string => $manifest->root, $manifests),
                );
            } catch (\InvalidArgumentException $exception) {
                $diagnostics[] = ['code' => 'PLUGIN_MODULE_CONFLICT', 'message' => $exception->getMessage()];
            }
            $tables = [];
            foreach ($manifests as $key => $manifest) {
                foreach ($manifest->data['database']['owned_tables'] ?? [] as $table) {
                    if (isset($tables[$table])) {
                        $diagnostics[] = ['code' => 'MODULE_PACKAGE_TABLE_OWNERSHIP_CONFLICT', 'module_key' => $key, 'owner' => $tables[$table], 'table' => $table];
                    }
                    $tables[$table] = $key;
                }
            }
            try {
                foreach (ModuleMigrationRun::sources($manifests, $this->serverRoot, true) as $source) {
                    unset($source['file']);
                    $migrations[] = $source;
                }
            } catch (\app\platform\exception\module\ModuleLifecycleException $exception) {
                $diagnostics[] = ['code' => $exception->errorCode, 'message' => $exception->getMessage()];
            }
            ksort($entries, SORT_STRING);
            $changes = [];
            foreach ($packages as $key => $package) {
                if (isset($modules[$key]) && in_array($modules[$key]['action'], ['add', 'upgrade'], true)) {
                    $changes[] = ['package' => $package, 'previous' => $current[$key] ?? null];
                }
            }
            $sourceChanges = [];
            try {
                $sourceChanges = $promoter->planSources($changes);
            } catch (ModulePackageException $exception) {
                $diagnostics[] = ['code' => $exception->errorCode, 'message' => $exception->getMessage()];
            }
            $nextLock = json_encode(['schema_version' => 1, 'modules' => array_values($entries)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if ($writes !== []) {
                $writes['modules.lock'] = hash('sha256', $nextLock);
            }
            ksort($writes, SORT_STRING);
            $plan = ['schema_version' => 1, 'protocol' => 'peanut.module-source-composition-plan.v1',
                'status' => $diagnostics === [] ? 'ready' : 'blocked', 'edition' => $edition, 'roots' => $roots,
                'composition' => ['version' => $composition['version'], 'sha256' => $compositionSha, 'preset' => $request['preset']],
                'index' => array_intersect_key($identity, array_flip(['repository', 'tag', 'commit', 'sha256'])),
                'kernel_version' => $kernel, 'host_composer_sha256' => hash_file('sha256', $this->serverRoot . '/composer.json'),
                'lock_before_sha256' => is_file($lockPath) ? hash_file('sha256', $lockPath) : null,
                'modules' => array_values($modules), 'dependency_order' => $diagnostics === [] ? $preflight->dependencyOrder($graph, []) : [],
                'writes' => $writes, 'source_changes' => $sourceChanges, 'migrations' => $migrations, 'diagnostics' => $diagnostics,
                'backup_requirements' => ['source' => 'native-journal-before-images', 'database' => 'before-first-install-only'],
                'compatibility' => ['source_graph' => $diagnostics === [] ? 'verified' : 'blocked',
                    'composer_npm' => 'native-preparation-required', 'api_web' => 'native-compilation-required'],
                'dependencies_installed' => false, 'runtime_activation' => 'not-verified',
                'pending_actions' => ['native_dependency_prepare', 'registry_compile', 'first_install', 'frontend_and_process_adoption'],
                'runtime_database_mutated' => false, 'tenant_modules_enabled' => false, 'rbac_granted' => false];
            $sha = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $response = ['plan' => $plan, 'plan_sha256' => $sha, 'recovery' => $promoter->recoveryState()];
            if ($approvedSha === null || $diagnostics !== []) {
                return $response;
            }
            if (!hash_equals($sha, $approvedSha)) {
                throw new ModulePackageException('MODULE_COMPOSITION_PLAN_CHANGED', 'Re-plan and review the changed input before source preparation.');
            }
            $response['recovery'] = $changes === [] ? $promoter->recoveryState() : $promoter->promoteMany($changes, $nextLock, $sha);
            $response['status'] = 'source-prepared';
            $response['completed_writes'] = array_keys($writes);
            return $response;
        } finally {
            foreach ($packages as $package) {
                $archive->cleanup($package);
            }
        }
    }

    /** Verify the transport digest and any explicitly selected signature before a source-only transaction. */
    public function adopt(string $archivePath, ?string $expectedSha256, ?string $signatureKeyId, ?string $expectedOfficialKey = null): array
    {
        $this->development();
        if (!is_string($expectedSha256) || preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new ModulePackageException('MODULE_PACKAGE_SOURCE_UNTRUSTED', 'Adoption requires the reviewed archive SHA-256.');
        }
        if ($signatureKeyId !== null
            && ($signatureKeyId === '' || !isset($this->trustedPublicKeys[$signatureKeyId]))) {
            throw new ModulePackageException('MODULE_PACKAGE_SOURCE_UNTRUSTED', 'The explicitly selected signature key must be trusted.');
        }
        if ($expectedOfficialKey !== null && preg_match('/^(?:peanut|official)\.[a-z][a-z0-9-]*$/D', $expectedOfficialKey) !== 1) {
            throw new ModulePackageException('MODULE_PACKAGE_OFFICIAL_SOURCE_UNTRUSTED', 'Official adoption requires an exact namespaced Module identity.');
        }
        $promoter = new ModulePackageSourcePromoter($this->serverRoot);
        return $promoter->run(function () use ($promoter, $archivePath, $expectedSha256, $signatureKeyId, $expectedOfficialKey): array {
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
                $official = str_starts_with($package->packageKey, 'peanut.') || str_starts_with($package->packageKey, 'official.');
                if ($official && $expectedOfficialKey === null) {
                    throw new ModulePackageException('MODULE_PACKAGE_PRIVATE_REQUIRED', 'Official source adoption requires a reviewed official Module identity.');
                }
                if ($expectedOfficialKey !== null && (!$official || !hash_equals($expectedOfficialKey, $package->packageKey))) {
                    throw new ModulePackageException('MODULE_PACKAGE_OFFICIAL_SOURCE_MISMATCH', 'The reviewed official Module identity differs from the package.');
                }
                if ($official && (($package->descriptor->source['type'] ?? null) !== 'canonical-contents'
                    || ($package->descriptor->trust['origin']['type'] ?? null) !== 'repository-contents'
                    || ($package->descriptor->trust['origin']['reference'] ?? null) !== 'canonical-plugin-contents-v1')) {
                    throw new ModulePackageException('MODULE_PACKAGE_OFFICIAL_SOURCE_UNTRUSTED', 'Official package source identity is not canonical.');
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
                    if ($comparison === 0 && (!hash_equals($previous->manifestDigest, $package->descriptor->manifestDigest)
                        || !hash_equals($previous->source['sha256'], $package->descriptor->source['sha256']))) {
                        throw new ModulePackageException('PACKAGE_VERSION_IDENTITY_CONFLICT', 'Same-version package identity changed.');
                    }
                    if ($official && $comparison === 0) {
                        $pinnedArchive = $promoter->pinnedArchiveSha($package->packageKey, $package->packageVersion, $previous->source['sha256']);
                        if ($pinnedArchive === null || !hash_equals($pinnedArchive, $package->archiveSha256)) {
                            throw new ModulePackageException('PACKAGE_VERSION_IDENTITY_CONFLICT', 'Same-version official archive identity cannot be verified.');
                        }
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
                    $promoter->promote($package, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", $previous);
                }
                return ['status' => 'development-adopted', 'operation' => $unchanged ? 'unchanged' : 'adopted',
                    'package_key' => $package->packageKey, 'version' => $package->packageVersion,
                    'archive_sha256' => $package->archiveSha256, 'signature_key_id' => $signatureKeyId,
                    'adoption_scope' => $official ? 'official' : 'private', 'runtime_activation' => 'not-verified', 'reconciliation_status' => 'pending',
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
