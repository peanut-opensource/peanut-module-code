<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Service;

use PeanutAdmin\Modules\Ops\Infrastructure\PairedBackupProvider;
use app\platform\value\ops\PlatformUpgradeTarget;
use app\common\value\installation\ApplicationReleaseVersions;
use app\common\value\installation\ServerReleaseIdentity;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\validation\module\StrictVersionConstraintMatcher;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Package;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceService;
use RuntimeException;
use Throwable;

/** Read-only PC41 projection over fixed deployment, backup, Module and scaffold evidence. */
final readonly class PlatformUpgradeReadinessService
{
    private const VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D';
    private const COMMIT = '/^[a-f0-9]{40}$/D';
    private const SHA256 = '/^[a-f0-9]{64}$/D';
    private const SLUG = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';
    private const PACKAGE_IDENTITY = '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D';

    public function __construct(
        private string $projectRoot,
        private ThinkPhpModuleGovernanceProvider $moduleGovernance,
        private PlatformBackupCenterService $backups,
        private MaintenanceService $maintenance,
        private PlatformPermissionChecker $permissions,
    ) {}

    /**
     * @param array{
     *   health:string,
     *   identity:array{commit:?string,tree:?string,release_key:?string,built_at:string,repository_clean:?bool},
     *   migrations:array{applied:int,target:int,pending:int,digest:string,drift:bool,files:array<string,string>}
     * } $runtime
     * @return array<string,mixed>
     */
    public function snapshot(PlatformContext $context, array $runtime): array
    {
        if (!$this->permissions->allows($context, Package::READ_PERMISSION)) {
            throw OpsConsoleException::denied();
        }

        if (!$this->isCommit($runtime['identity']['commit'])
            || !$this->isCommit($runtime['identity']['tree'])
            || !is_bool($runtime['identity']['repository_clean'])) {
            return $this->projection(
                [$this->check('source.identity', 'blocked', 'SOURCE_IDENTITY_UNKNOWN')],
                $runtime, null, null, $this->migrationUnavailable($runtime),
                $this->moduleUnavailable(), $this->scaffoldUnavailable(), $this->backupUnavailable(),
                null, null, 1,
            );
        }

        $checks = [];
        $sourceError = null;
        try {
            $source = $this->sourceIdentity();
        } catch (RuntimeException $exception) {
            $source = null;
            $sourceError = $this->stableCode($exception, 'UPGRADE_SOURCE_APPLICATION_MANIFEST_INVALID');
        }
        try {
            $target = PlatformUpgradeTarget::load($this->projectRoot);
            $targetProjection = $this->targetProjection($target);
            $checks[] = $this->check('target.release', 'ready', 'UPGRADE_TARGET_RELEASE_READY');
        } catch (RuntimeException $exception) {
            $code = $this->stableCode($exception, 'UPGRADE_TARGET_DESCRIPTOR_INVALID');
            $state = $code === 'UPGRADE_TARGET_NOT_STAGED' ? 'configuration_required' : 'blocked';
            $checks[] = $this->check('target.release', $state, $code);
            if ($sourceError !== null) {
                $checks[] = $this->check('source.identity', 'blocked', $sourceError);
            } elseif ($source === null) {
                $checks[] = $this->check(
                    'source.identity',
                    'configuration_required',
                    'UPGRADE_SOURCE_APPLICATION_MANIFEST_REQUIRED',
                );
            } else {
                $checks[] = $this->check('source.identity', 'ready', 'UPGRADE_SOURCE_IDENTITY_READY');
            }
            return $this->projection(
                $checks,
                $runtime,
                $source,
                null,
                $this->migrationUnavailable($runtime),
                $this->moduleUnavailable(),
                $this->scaffoldUnavailable(),
                $this->backupUnavailable(),
                null,
                null,
                2,
            );
        }

        if ($sourceError !== null || $source === null) {
            $checks[] = $this->check(
                'source.identity',
                $sourceError === null ? 'configuration_required' : 'blocked',
                $sourceError ?? 'UPGRADE_SOURCE_APPLICATION_MANIFEST_REQUIRED',
            );
            return $this->projection(
                $checks,
                $runtime,
                null,
                $targetProjection,
                $this->migrationUnavailable($runtime, $target),
                $this->moduleUnavailable($target),
                $this->scaffoldUnavailable(),
                $this->backupUnavailable(),
                null,
                null,
                2,
            );
        }
        $checks[] = $this->check('source.identity', 'ready', 'UPGRADE_SOURCE_IDENTITY_READY');

        $directionCode = $this->directionCode($source, $target);
        $checks[] = $directionCode === null
            ? $this->check('version.direction', 'ready', 'UPGRADE_VERSION_DIRECTION_READY')
            : $this->check('version.direction', 'blocked', $directionCode);

        $checks[] = $runtime['health'] === 'unhealthy'
            ? $this->check('runtime.health', 'blocked', 'UPGRADE_RUNTIME_UNHEALTHY')
            : $this->check('runtime.health', 'ready', 'UPGRADE_RUNTIME_HEALTH_READY');
        $checks[] = $runtime['identity']['repository_clean']
            ? $this->check('source.repository', 'ready', 'UPGRADE_REPOSITORY_CLEAN')
            : $this->check('source.repository', 'blocked', 'UPGRADE_REPOSITORY_DIRTY');

        $migration = $this->migrationProjection($runtime, $target);
        $checks[] = $migration['blockers'] === []
            ? $this->check('database.migrations', 'ready', 'UPGRADE_MIGRATIONS_READY')
            : $this->check('database.migrations', 'blocked', $migration['blockers'][0]);

        $scaffold = $this->scaffoldProjection($target);
        $checks[] = $scaffold['status'] === 'ready'
            ? $this->check('scaffold.plan', 'ready', 'UPGRADE_SCAFFOLD_READY')
            : $this->check('scaffold.plan', 'blocked', (string) $scaffold['code']);

        $modules = $this->moduleProjection($target);
        $checks[] = $modules['status'] === 'ready'
            ? $this->check('module.compatibility', 'ready', 'UPGRADE_MODULES_READY')
            : $this->check('module.compatibility', 'blocked', (string) $modules['blockers'][0]);

        $staticCheckCount = count($checks);
        $backup = $this->backupProjection($context, $runtime['identity']['commit']);
        if (($backup['analysis_code'] ?? null) === 'UPGRADE_BACKUP_EVIDENCE_INVALID') {
            $checks[] = $this->check('backup.verified', 'blocked', 'UPGRADE_BACKUP_EVIDENCE_INVALID');
        } else {
            $checks[] = $backup['latest_verified'] === null
                ? $this->check('backup.verified', 'blocked', 'UPGRADE_VERIFIED_BACKUP_REQUIRED')
                : (($backup['latest_verified']['source_matches_runtime'] ?? false) === true
                    ? $this->check('backup.verified', 'ready', 'UPGRADE_VERIFIED_BACKUP_READY')
                    : $this->check('backup.verified', 'blocked', 'UPGRADE_BACKUP_SOURCE_MISMATCH'));
        }
        $latestBackup = is_array($backup['latest_verified'] ?? null)
            ? $backup['latest_verified']
            : null;
        $latestRestore = is_array($backup['latest_restore_verified'] ?? null)
            ? $backup['latest_restore_verified']
            : null;
        if ($latestRestore === null) {
            $checks[] = $this->check(
                'restore.verified',
                'blocked',
                'UPGRADE_RESTORE_EVIDENCE_REQUIRED',
            );
        } elseif ($latestBackup === null
            || !hash_equals(
                (string) $latestBackup['backup_reference_key'],
                (string) $latestRestore['backup_reference_key'],
            )) {
            $checks[] = $this->check(
                'restore.verified',
                'blocked',
                'UPGRADE_RESTORE_BACKUP_MISMATCH',
            );
        } else {
            $checks[] = $this->check(
                'restore.verified',
                'ready',
                'UPGRADE_RESTORE_EVIDENCE_READY',
            );
        }

        $maintenance = $this->maintenanceProjection($context);
        if ($maintenance === null) {
            $checks[] = $this->check('maintenance.window', 'blocked', 'UPGRADE_MAINTENANCE_REQUIRED');
        } elseif (($maintenance['reason_key'] ?? null) !== 'planned-upgrade') {
            $checks[] = $this->check('maintenance.window', 'blocked', 'UPGRADE_MAINTENANCE_REASON_INVALID');
        } elseif (($maintenance['state'] ?? null) !== 'active') {
            $checks[] = $this->check('maintenance.window', 'blocked', 'UPGRADE_MAINTENANCE_NOT_ACTIVE');
        } else {
            $checks[] = $this->check('maintenance.window', 'ready', 'UPGRADE_MAINTENANCE_READY');
        }

        return $this->projection(
            $checks,
            $runtime,
            $source,
            $targetProjection,
            $migration,
            $modules,
            $scaffold,
            $backup,
            $maintenance,
            $this->recoveryPointer($backup),
            $staticCheckCount,
        );
    }

    /** Read the current application's stable identity, product release and adopted scaffold provenance. */
    private function sourceIdentity(): ?array
    {
        $serverIdentityPath = $this->projectRoot . '/server/.peanut/release-identity.json';
        if (file_exists($serverIdentityPath) || is_link($serverIdentityPath)) {
            try {
                $identity = ServerReleaseIdentity::resolve($this->projectRoot . '/server');
            } catch (RuntimeException $exception) {
                throw new RuntimeException('UPGRADE_SOURCE_SERVER_RELEASE_INVALID', 0, $exception);
            }
            $application = $identity->applicationIdentity();
            $template = $identity->templateIdentity();
            $versions = $identity->versions();
            $applicationVersion = $application['version'] ?? null;
            $productRelease = $versions['release_sequence_version'] ?? null;
            $slug = $application['slug'] ?? null;
            $packageIdentity = $application['package_identity'] ?? null;
            if (!is_string($applicationVersion) || preg_match(self::VERSION, $applicationVersion) !== 1
                || !is_string($productRelease) || preg_match(self::VERSION, $productRelease) !== 1
                || !is_string($slug) || strlen($slug) > 63 || preg_match(self::SLUG, $slug) !== 1
                || !is_string($packageIdentity) || strlen($packageIdentity) > 120
                || preg_match(self::PACKAGE_IDENTITY, $packageIdentity) !== 1
                || !is_string($template['version'] ?? null) || preg_match(self::VERSION, $template['version']) !== 1
                || !$this->isCommit($template['source_commit'] ?? null)
                || !$this->isCommit($template['source_tree'] ?? null)
                || !$this->isSha256($template['inventory_sha256'] ?? null)
            ) {
                throw new RuntimeException('UPGRADE_SOURCE_SERVER_RELEASE_INVALID');
            }
            return [
                'application_version' => $applicationVersion,
                'product_release' => $productRelease,
                'slug' => $slug,
                'package_identity' => $packageIdentity,
                'template_version' => $template['version'],
                'template_source_commit' => $template['source_commit'],
                'template_source_tree' => $template['source_tree'],
                'template_inventory_sha256' => $template['inventory_sha256'],
                'application_manifest_sha256' => $identity->manifestSha256(),
            ];
        }

        $path = $this->projectRoot . '/.peanut/application-manifest.json';
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $resolved = realpath($path);
        $root = realpath($this->projectRoot);
        if ($resolved === false || $root === false
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('UPGRADE_SOURCE_APPLICATION_MANIFEST_INVALID');
        }
        try {
            $raw = file_get_contents($resolved);
            $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            throw new RuntimeException('UPGRADE_SOURCE_APPLICATION_MANIFEST_INVALID');
        }
        if (!is_array($data)) {
            throw new RuntimeException('UPGRADE_SOURCE_APPLICATION_MANIFEST_INVALID');
        }
        $application = is_array($data['application'] ?? null) ? $data['application'] : [];
        $template = is_array($data['template'] ?? null) ? $data['template'] : [];
        $slug = $application['slug'] ?? null;
        $packageIdentity = $application['package_identity'] ?? null;
        if (($data['schema_version'] ?? null) !== 2
            || ($data['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
            || !is_string($application['version'] ?? null)
            || preg_match(self::VERSION, $application['version']) !== 1
            || !is_string($slug) || strlen($slug) > 63 || preg_match(self::SLUG, $slug) !== 1
            || !is_string($packageIdentity) || strlen($packageIdentity) > 120
            || preg_match(self::PACKAGE_IDENTITY, $packageIdentity) !== 1
            || !is_string($template['version'] ?? null)
            || preg_match(self::VERSION, $template['version']) !== 1
            || !$this->isCommit($template['source_commit'] ?? null)
            || !$this->isCommit($template['source_tree'] ?? null)
            || !$this->isSha256($template['inventory_sha256'] ?? null)
            || !is_string($raw)) {
            throw new RuntimeException('UPGRADE_SOURCE_APPLICATION_MANIFEST_INVALID');
        }
        try {
            $versions = ApplicationReleaseVersions::load($this->sourceFile('release-versions.json'));
        } catch (RuntimeException $exception) {
            throw new RuntimeException('UPGRADE_SOURCE_APPLICATION_RELEASE_VERSIONS_INVALID', 0, $exception);
        }
        $metadata = $this->sourceJson(
            $this->sourceFile('RELEASE_METADATA.json'),
            'UPGRADE_SOURCE_RELEASE_METADATA_INVALID',
        );
        $productRelease = $versions->releaseSequenceVersion();
        $metadataVersion = ($metadata['schema_version'] ?? null) === 2
            && ($metadata['protocol'] ?? null) === 'peanut.release-metadata.v2'
            ? ($metadata['instance_version'] ?? $metadata['source_product_version'] ?? null)
            : ($metadata['version'] ?? null);
        if (!is_string($metadata['application_identity'] ?? null)
            || !is_string($metadataVersion)
            || !is_string($metadata['expected_tag'] ?? null)
            || !hash_equals($packageIdentity, $metadata['application_identity'])
            || $metadataVersion !== $productRelease
            || (($metadata['schema_version'] ?? null) === 2
                && ($metadata['source_product_version'] ?? null) !== $versions->sourceProductVersion())
            || $metadata['expected_tag'] !== 'v' . $productRelease
            || $versions->scaffoldTemplate() !== $template['version']) {
            throw new RuntimeException('UPGRADE_SOURCE_RELEASE_METADATA_INVALID');
        }
        return [
            'application_version' => $application['version'],
            'product_release' => $productRelease,
            'slug' => $slug,
            'package_identity' => $packageIdentity,
            'template_version' => $template['version'],
            'template_source_commit' => $template['source_commit'],
            'template_source_tree' => $template['source_tree'],
            'template_inventory_sha256' => $template['inventory_sha256'],
            'application_manifest_sha256' => hash('sha256', $raw),
        ];
    }

    /** Reject cross-application deployment, product non-upgrades and unsupported scaffold movement. */
    private function directionCode(array $source, PlatformUpgradeTarget $target): ?string
    {
        if (!hash_equals((string) $source['slug'], $target->application['slug'])
            || !hash_equals((string) $source['package_identity'], $target->application['package_identity'])) {
            return 'UPGRADE_APPLICATION_IDENTITY_MISMATCH';
        }
        if (version_compare((string) $source['product_release'], $target->application['product_release'], '>=')) {
            return 'UPGRADE_TARGET_NOT_NEWER';
        }
        $sourceTemplate = [
            'version' => (string) $source['template_version'],
            'source_commit' => (string) $source['template_source_commit'],
            'source_tree' => (string) $source['template_source_tree'],
            'inventory_sha256' => (string) $source['template_inventory_sha256'],
        ];
        if (!$this->sameScaffoldRelease($sourceTemplate, $target->sourceTemplate)) {
            return 'UPGRADE_SOURCE_RELEASE_MISMATCH';
        }
        $from = $target->sourceTemplate['version'];
        $to = $target->template['version'];
        if (version_compare($from, $to, '>')) {
            return 'UPGRADE_SCAFFOLD_DOWNGRADE_FORBIDDEN';
        }
        if (explode('.', $from, 2)[0] !== explode('.', $to, 2)[0]) {
            return 'UPGRADE_FRESH_REBUILD_REQUIRED';
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function targetProjection(PlatformUpgradeTarget $target): array
    {
        $manifest = json_decode((string) file_get_contents($target->toManifestPath), true, 512, JSON_THROW_ON_ERROR);
        $release = is_array($manifest['release'] ?? null) ? $manifest['release'] : [];
        return [
            'release_key' => $target->release['key'],
            'commit' => $target->release['commit'],
            'tree' => $target->release['tree'],
            'descriptor_sha256' => $target->descriptorSha256,
            'qualification' => [
                'status' => $target->release['qualification']['status'],
                'groups_passed' => $target->release['qualification']['groups_passed'],
                'cleanup_residual_count' => $target->release['qualification']['cleanup_residual_count'],
                'lease_released' => $target->release['qualification']['lease_released'],
            ],
            'application' => $target->application,
            'scaffold' => [
                'from_version' => $target->scaffold['from_version'],
                'to_version' => $target->scaffold['to_version'],
                'source_commit' => $release['source_commit'] ?? null,
                'source_tree' => $release['source_tree'] ?? null,
                'inventory_sha256' => $release['inventory_sha256'] ?? null,
                'manifest_sha256' => $target->scaffold['to_manifest_sha256'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function migrationProjection(array $runtime, PlatformUpgradeTarget $target): array
    {
        $current = $runtime['migrations']['files'];
        $from = $target->sourceMigrationMap();
        $to = $target->targetMigrationMap();
        $blockers = [];
        if ($runtime['migrations']['drift']) {
            $blockers[] = 'UPGRADE_MIGRATION_DRIFT';
        }
        if ($runtime['migrations']['pending'] > 0) {
            $blockers[] = 'UPGRADE_CURRENT_MIGRATIONS_PENDING';
        }
        foreach ($from as $id => $digest) {
            if (!isset($current[$id])) {
                $blockers[] = 'UPGRADE_SOURCE_MIGRATION_MISSING';
            } elseif (!hash_equals($digest, $current[$id])) {
                $blockers[] = 'UPGRADE_SOURCE_MIGRATION_REWRITTEN';
            }
            if (!isset($to[$id])) {
                $blockers[] = 'UPGRADE_TARGET_MIGRATION_REMOVED';
            } elseif (!hash_equals($digest, $to[$id])) {
                $blockers[] = 'UPGRADE_TARGET_MIGRATION_REWRITTEN';
            }
        }
        $maxSource = $from === [] ? null : array_key_last($from);
        $pending = [];
        foreach (array_diff_key($to, $from) as $id => $digest) {
            if ($maxSource !== null && strcmp($id, $maxSource) <= 0) {
                $blockers[] = 'UPGRADE_TARGET_MIGRATION_BACKDATED';
            }
            if (isset($current[$id]) && !hash_equals($digest, $current[$id])) {
                $blockers[] = 'UPGRADE_TARGET_MIGRATION_COLLISION';
            } elseif (!isset($current[$id])) {
                $pending[] = $id;
            }
        }
        $blockers = array_values(array_unique($blockers));
        return [
            'current' => [
                'applied' => $runtime['migrations']['applied'],
                'target' => $runtime['migrations']['target'],
                'pending' => $runtime['migrations']['pending'],
                'inventory_sha256' => $runtime['migrations']['digest'],
                'drift' => $runtime['migrations']['drift'],
            ],
            'release' => [
                'from_count' => count($from),
                'from_inventory_sha256' => $target->migrations['from']['inventory_sha256'],
                'to_count' => count($to),
                'to_inventory_sha256' => $target->migrations['to']['inventory_sha256'],
                'pending_count' => count($pending),
            ],
            'blockers' => $blockers,
        ];
    }

    /** @return array<string,mixed> */
    private function moduleProjection(PlatformUpgradeTarget $target): array
    {
        try {
            $resolver = new PluginLockResolver(
                $target->releaseServerRoot,
                $target->targetLockPath,
            );
            $targetModules = [];
            foreach ($resolver->all() as $plugin) {
                if (($plugin->trustResult()['status'] ?? null) !== 'eligible') {
                    throw new PluginLifecycleException(
                        'PLUGIN_TRUST_QUALIFICATION_INVALID',
                        'Target Plugin trust is not eligible.',
                    );
                }
                foreach (($plugin->trust['compatibility']['modules'] ?? []) as $module) {
                    if (!is_array($module) || !is_string($module['key'] ?? null)
                        || isset($targetModules[$module['key']])) {
                        throw new RuntimeException('UPGRADE_TARGET_MODULE_LOCK_INVALID');
                    }
                    $targetModules[$module['key']] = $module;
                }
            }
            ksort($targetModules, SORT_STRING);
            $installed = $this->moduleGovernance
                ->qualification()
                ->installedModules();
            $installedVersions = [];
            foreach ($installed as $module) {
                $installedVersions[$module->moduleKey] = $module->version;
            }
            ksort($installedVersions, SORT_STRING);
            $matcher = new StrictVersionConstraintMatcher();
            $kernel = $target->modules['kernel_version'];
            $compatible = 0;
            $blockers = [];
            foreach ($installedVersions as $key => $version) {
                $targetModule = $targetModules[$key] ?? null;
                if (!is_array($targetModule)) {
                    $blockers[] = 'UPGRADE_MODULE_REMOVED';
                    continue;
                }
                $targetVersion = (string) ($targetModule['version'] ?? '');
                if (version_compare($targetVersion, $version, '<')) {
                    $blockers[] = 'UPGRADE_MODULE_DOWNGRADE_FORBIDDEN';
                    continue;
                }
                if (!$matcher->matches($kernel, (string) ($targetModule['kernel_constraint'] ?? ''))) {
                    $blockers[] = 'UPGRADE_MODULE_KERNEL_INCOMPATIBLE';
                    continue;
                }
                $dependencyCompatible = true;
                foreach (($targetModule['dependencies'] ?? []) as $dependency) {
                    $dependencyKey = is_array($dependency) ? ($dependency['module_key'] ?? null) : null;
                    $constraint = is_array($dependency) ? ($dependency['version'] ?? null) : null;
                    $available = is_string($dependencyKey)
                        ? ($targetModules[$dependencyKey]['version'] ?? null)
                        : null;
                    if (!is_string($available) || !is_string($constraint)
                        || !$matcher->matches($available, $constraint)) {
                        $dependencyCompatible = false;
                        break;
                    }
                }
                if (!$dependencyCompatible) {
                    $blockers[] = 'UPGRADE_MODULE_DEPENDENCY_INCOMPATIBLE';
                    continue;
                }
                $compatible++;
            }
            $blockers = array_values(array_unique($blockers));
            return [
                'status' => $blockers === [] ? 'ready' : 'blocked',
                'installed_count' => count($installedVersions),
                'compatible_count' => $compatible,
                'target_count' => count($targetModules),
                'lock_sha256' => $target->modules['lock_sha256'],
                'target_kernel_version' => $kernel,
                'blockers' => $blockers,
            ];
        } catch (PluginLifecycleException $exception) {
            $code = $exception->errorCode === 'PLUGIN_ARTIFACT_MISMATCH'
                ? 'UPGRADE_MODULE_APP_OWNED_CONFLICT'
                : 'UPGRADE_TARGET_MODULE_LOCK_INVALID';
        } catch (Throwable) {
            $code = 'UPGRADE_TARGET_MODULE_LOCK_INVALID';
        }
        return [
            'status' => 'blocked',
            'installed_count' => 0,
            'compatible_count' => 0,
            'target_count' => 0,
            'lock_sha256' => $target->modules['lock_sha256'],
            'target_kernel_version' => $target->modules['kernel_version'],
            'blockers' => [$code],
        ];
    }

    /** Report the scaffold provenance already adopted by the verified full application release. */
    private function scaffoldProjection(PlatformUpgradeTarget $target): array
    {
        return [
            'status' => 'ready',
            'code' => 'UPGRADE_SCAFFOLD_PROVENANCE_READY',
            'candidate' => $target->descriptorSha256,
            'automatic' => 0,
            'preserved' => 0,
            'conflicts' => 0,
            'managed_pre_sha256' => null,
            'app_owned_pre_sha256' => null,
            'app_owned_count' => 0,
            'conflict_reasons' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function backupProjection(PlatformContext $context, string $runtimeCommit): array
    {
        try {
            return $this->backups->snapshot($context, $runtimeCommit);
        } catch (Throwable) {
            return $this->backupUnavailable('UPGRADE_BACKUP_EVIDENCE_INVALID');
        }
    }

    /** @return array<string,mixed>|null */
    private function maintenanceProjection(PlatformContext $context): ?array
    {
        try {
            return $this->maintenance
                ->current($context)
                ?->toPublicArray();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function recoveryPointer(array $backup): ?array
    {
        $latest = $backup['latest_verified'] ?? null;
        $restore = $backup['latest_restore_verified'] ?? null;
        if (!is_array($latest) || !is_array($restore)
            || ($latest['source_matches_runtime'] ?? false) !== true
            || !hash_equals(
                (string) $latest['backup_reference_key'],
                (string) $restore['backup_reference_key'],
            )) {
            return null;
        }
        return [
            'provider_key' => $latest['provider_key'],
            'backup_reference_key' => $latest['backup_reference_key'],
            'manifest_sha256' => $latest['manifest_sha256'],
            'restore_target_key' => $restore['target_key'],
            'restore_verification_sha256' => $restore['verification_sha256'],
            'restore_verified_at' => $restore['verified_at'],
        ];
    }

    /** @return array<string,mixed> */
    private function projection(
        array $checks,
        array $runtime,
        ?array $source,
        ?array $target,
        array $migrations,
        array $modules,
        array $scaffold,
        array $backup,
        ?array $maintenance,
        ?array $recoveryPointer,
        int $staticCheckCount,
    ): array {
        [$state, $code] = $this->overall($checks);
        [$preflightState, $preflightCode] = $this->overall(array_slice($checks, 0, $staticCheckCount));
        return [
            'schema_version' => 1,
            'state' => $state,
            'code' => $code,
            'preflight' => ['state' => $preflightState, 'code' => $preflightCode],
            'checks' => $checks,
            'source' => [
                'runtime' => [
                    'commit' => $runtime['identity']['commit'],
                    'tree' => $runtime['identity']['tree'],
                    'release_key' => $runtime['identity']['release_key'],
                    'repository_clean' => $runtime['identity']['repository_clean'],
                ],
                'application' => $source,
            ],
            'target' => $target,
            'migrations' => $migrations,
            'modules' => $modules,
            'scaffold' => $scaffold,
            'backup' => $backup,
            'maintenance' => $maintenance,
            'recovery_pointer' => $recoveryPointer,
        ];
    }

    /** @return array{string,string} */
    private function overall(array $checks): array
    {
        foreach ($checks as $check) {
            if ($check['status'] !== 'ready') {
                return [$check['status'], $check['code']];
            }
        }
        return ['ready', 'UPGRADE_READY'];
    }

    /** @return array{key:string,status:string,code:string} */
    private function check(string $key, string $status, string $code): array
    {
        return ['key' => $key, 'status' => $status, 'code' => $code];
    }

    private function stableCode(RuntimeException $exception, string $fallback): string
    {
        $code = trim($exception->getMessage());
        return preg_match('/^UPGRADE_[A-Z0-9_]{1,112}$/D', $code) === 1 ? $code : $fallback;
    }

    /** Resolve a fixed current-application identity file without accepting links or escapes. */
    private function sourceFile(string $relative): string
    {
        $root = realpath($this->projectRoot);
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($root === false || !is_file($path) || is_link($path)) {
            throw new RuntimeException('UPGRADE_SOURCE_IDENTITY_FILE_INVALID');
        }
        $resolved = realpath($path);
        if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('UPGRADE_SOURCE_IDENTITY_FILE_INVALID');
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    private function sourceJson(string $path, string $code): array
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

    /** @param array<string,string> $left @param array<string,string> $right */
    private function sameScaffoldRelease(array $left, array $right): bool
    {
        foreach (['version', 'source_commit', 'source_tree', 'inventory_sha256'] as $key) {
            if (!isset($left[$key], $right[$key]) || !hash_equals($left[$key], $right[$key])) {
                return false;
            }
        }
        return true;
    }

    private function isCommit(mixed $value): bool
    {
        return is_string($value) && preg_match(self::COMMIT, $value) === 1;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SHA256, $value) === 1;
    }

    /** @return array<string,mixed> */
    private function migrationUnavailable(array $runtime, ?PlatformUpgradeTarget $target = null): array
    {
        return [
            'current' => [
                'applied' => $runtime['migrations']['applied'],
                'target' => $runtime['migrations']['target'],
                'pending' => $runtime['migrations']['pending'],
                'inventory_sha256' => $runtime['migrations']['digest'],
                'drift' => $runtime['migrations']['drift'],
            ],
            'release' => $target === null ? null : [
                'from_count' => count($target->sourceMigrationMap()),
                'from_inventory_sha256' => $target->migrations['from']['inventory_sha256'],
                'to_count' => count($target->targetMigrationMap()),
                'to_inventory_sha256' => $target->migrations['to']['inventory_sha256'],
                'pending_count' => null,
            ],
            'blockers' => ['UPGRADE_MIGRATION_ANALYSIS_PENDING'],
        ];
    }

    /** @return array<string,mixed> */
    private function moduleUnavailable(?PlatformUpgradeTarget $target = null): array
    {
        return [
            'status' => 'configuration_required',
            'installed_count' => 0,
            'compatible_count' => 0,
            'target_count' => 0,
            'lock_sha256' => $target?->modules['lock_sha256'] ?? null,
            'target_kernel_version' => $target?->modules['kernel_version'] ?? null,
            'blockers' => ['UPGRADE_MODULE_ANALYSIS_PENDING'],
        ];
    }

    /** @return array<string,mixed> */
    private function scaffoldUnavailable(string $code = 'UPGRADE_SCAFFOLD_ANALYSIS_PENDING'): array
    {
        return [
            'status' => $code === 'UPGRADE_SCAFFOLD_ANALYSIS_PENDING'
                ? 'configuration_required'
                : 'blocked',
            'code' => $code,
            'candidate' => null,
            'automatic' => 0,
            'preserved' => 0,
            'conflicts' => 0,
            'managed_pre_sha256' => null,
            'app_owned_pre_sha256' => null,
            'app_owned_count' => 0,
            'conflict_reasons' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function backupUnavailable(string $code = 'UPGRADE_BACKUP_ANALYSIS_PENDING'): array
    {
        return [
            'provider' => ['key' => PairedBackupProvider::PROVIDER_KEY],
            'latest_verified' => null,
            'latest_restore_verified' => null,
            'tasks' => [],
            'analysis_code' => $code,
        ];
    }
}
