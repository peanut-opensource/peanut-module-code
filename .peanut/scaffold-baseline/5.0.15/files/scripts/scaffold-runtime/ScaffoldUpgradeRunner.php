<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

if (!class_exists(\app\common\infrastructure\scaffold\ReleaseDependencyIdentity::class, false)) {
    require_once dirname(__DIR__, 2) . '/server/app/common/infrastructure/scaffold/ReleaseDependencyIdentity.php';
}

use app\common\validation\scaffold\ScaffoldPathGuard;
use app\common\value\scaffold\ScaffoldManifest;
use app\common\value\scaffold\ScaffoldSourceIdentity;
use app\platform\value\plugin\PluginDescriptor;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\PluginArtifactWriter;
use app\platform\validation\module\StrictVersionConstraintMatcher;
use app\platform\validation\module\OpisManifestSchemaValidator;
use app\common\module\PackageCheck;
use RuntimeException;
use Throwable;

/** Plan, apply, verify, and recover scaffold-owned changes with frozen application identities. */
final class ScaffoldUpgradeRunner
{
    /** Real process-local open file descriptions, never a caller-supplied "locked" flag. */
    private static array $sourceLocks = [];
    public static function sourceProductLock(string $root, callable $operation): mixed
    {
        return self::sourceLock(ScaffoldPathGuard::projectRoot($root) . '/.peanut/upgrades/product.lock', $operation);
    }

    private static function sourceLock(string $path, callable $operation): mixed
    {
        ScaffoldPathGuard::ensureDirectory(dirname($path));
        if (isset(self::$sourceLocks[$path])) {
            $held = self::$sourceLocks[$path];
            $stat = @lstat($path);
            $open = fstat($held['handle']);
            if ($held['pid'] !== getmypid() || !is_array($stat) || is_link($path)
                || $open['dev'] !== $stat['dev'] || $open['ino'] !== $stat['ino']) {
                throw new RuntimeException('SCAFFOLD_SOURCE_LOCK_CHANGED');
            }
            return $operation();
        }
        if (is_link($path) || (file_exists($path) && (!is_file($path) || lstat($path)['nlink'] !== 1))) {
            throw new RuntimeException('SCAFFOLD_SOURCE_LOCK_UNSAFE');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false || !chmod($path, 0600) || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('SCAFFOLD_SOURCE_LOCK_BUSY');
        }
        self::$sourceLocks[$path] = ['handle' => $handle, 'pid' => getmypid()];
        try { return $operation(); }
        finally { unset(self::$sourceLocks[$path]); flock($handle, LOCK_UN); fclose($handle); }
    }
    private function changesNotificationExecution(array $plan): bool
    {
        $prefix = 'server/app/modules/peanut/notification/';
        foreach ($plan['actions'] ?? [] as $action) {
            if (!in_array($action['action'] ?? '', ['create', 'replace', 'regenerate', 'delete', 'rename', 'rename-preserve'], true)) {
                continue;
            }
            $path = $action['path'] ?? '';
            if (is_string($path) && str_starts_with($path, $prefix)
                && ($path === $prefix . 'module.json'
                    || str_starts_with($path, $prefix . 'src/')
                    || str_starts_with($path, $prefix . 'database/'))) {
                return true;
            }
        }
        return false;
    }

    /** The operator still owns the exclusive runtime-safe window; this never stops services. */
    private function notificationExecutionPreflight(string $root): array
    {
        $unknown = ['status' => 'unknown', 'may_apply' => false, 'code' => 'NOTIFICATION_UPGRADE_STATE_UNKNOWN'];
        $entry = $root . '/server/module';
        if (!is_file($entry) || is_link($entry)) { return $unknown; }
        $process = proc_open(
            [PHP_BINARY, $entry],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root . '/server',
        );
        if (!is_resource($process)) { return $unknown; }
        fwrite($pipes[0], json_encode(['operation' => 'check', 'arguments' => ['peanut.notification', '--runtime-data']], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $exit = -1;
        $deadline = microtime(true) + 15;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // Never expose arbitrary APP errors or environment values.
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            if (strlen($stdout) > 8192 || microtime(true) >= $deadline) {
                proc_terminate($process);
                break;
            }
            usleep(50000);
        } while (true);
        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (strlen($stdout) > 8192) { return $unknown; }
        foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
            $report = json_decode($line, true);
            if (!is_array($report) || ($report['schema_version'] ?? null) !== 1
                || ($report['module_key'] ?? null) !== 'peanut.notification') { continue; }
            if ($exit === 0 && ($report['may_apply'] ?? false) === true
                && ($report['status'] ?? '') === 'ready' && ($report['pending_count'] ?? null) === 0) {
                return ['status' => 'ready', 'pending_count' => 0, 'may_apply' => true, 'code' => null,
                    'runtime_safe_window_required' => true];
            }
            if (($report['status'] ?? '') === 'blocked' && is_int($report['pending_count'] ?? null)
                && $report['pending_count'] > 0) {
                return ['status' => 'blocked', 'pending_count' => $report['pending_count'], 'may_apply' => false,
                    'code' => 'NOTIFICATION_LEGACY_DELIVERY_PENDING'];
            }
        }
        return $unknown;
    }

    public function __construct(
        private readonly ?int $failAfterReplacements = null,
        private readonly ?int $failAfterAdoptionWrites = null,
    ) {
        if (($failAfterReplacements !== null && $failAfterReplacements < 1)
            || ($failAfterAdoptionWrites !== null && $failAfterAdoptionWrites < 1)) {
            throw new RuntimeException('SCAFFOLD_FAULT_INJECTION_INVALID');
        }
    }

    private const STRICT_SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    private const VERSION_CONTRACT_V1_KEYS = [
        'schema_version',
        'protocol',
        'product_release',
        'scaffold_template',
        'generated_application_default',
        'core_php',
        'core_web',
    ];
    private const VERSION_CONTRACT_V2_KEYS = [
        'schema_version',
        'protocol',
        'source_product_version',
        'instance_version',
        'scaffold_template',
        'generated_instance_default',
        'core_php',
        'core_web',
    ];
    private const VERSION_CONTRACT_V3_KEYS = self::VERSION_CONTRACT_V2_KEYS;
    private const CORE_WEB_PACKAGES = [
        '@peanut-admin/client',
        '@peanut-admin/vue',
        '@peanut-admin/ui-vue',
        '@peanut-admin/nuxt',
        '@peanut-admin/uniapp',
        '@peanut-admin/testing',
    ];

    /**
     * Build the immutable scaffold plan without writing a plan file or ledger event.
     *
     * The Platform upgrade-readiness projection uses this method so a read request
     * can evaluate exactly the same ownership/conflict rules as the CLI preflight.
     */
    public function preview(string $projectRoot, string $fromManifestPath, string $toManifestPath, ?array $sourceSelection = null): array
    {
        return $this->previewSource($projectRoot, $fromManifestPath, $toManifestPath, $sourceSelection);
    }

    private function previewSource(string $projectRoot, string $fromManifestPath, string $toManifestPath, ?array $sourceSelection): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        [$application, $applicationDigest] = $this->applicationManifest($root);
        $from = ScaffoldManifest::load($fromManifestPath, true);
        $from->assertRecordedApplication($root, $application, $applicationDigest);
        $to = ScaffoldManifest::load($toManifestPath);
        if ($to->isRecordedBaseline()) { throw new RuntimeException('SCAFFOLD_TARGET_REQUIRES_RELEASE_MANIFEST'); }
        $sourceSelection = $this->validateUpgradeSourceSelection($root, $sourceSelection, $to);
        $development = null;
        if (isset($sourceSelection['source_checkout']) || ($to->sourceIdentity()['kind'] === 'local' && isset($sourceSelection['template_package_path']))) {
            if (!$from->isRecordedBaseline()
                || ScaffoldSourceIdentity::from($application['generation_source'] ?? [], true) !== $from->sourceIdentity()
                || ($application['generation_source']['inventory_sha256'] ?? null) !== $from->release()['inventory_sha256']
                || ($application['edition']['source_sha256'] ?? null) !== ($application['generation_source']['edition_profile_sha256'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_DEVELOPMENT_BASE_INVALID');
            }
            $workflow = $this->developmentWorkflow($application['application'], $from, $to);
            $development = $this->developmentSource($application['generation_source'], $to, $sourceSelection,
                $application['application']['profile'] ?? '', $workflow['selection'], $from->version(), $workflow['paths']);
            $to = $to->withoutPaths($development['excluded_paths']);
        }
        $publishedAdoption = $from->isRecordedBaseline() && $from->version() === $to->version()
            && isset($sourceSelection['template_package_path'])
            && ($sourceSelection['channel'] ?? null) !== 'development'
            && ($application['generation_source']['channel'] ?? null) === 'development';
        $this->assertReleaseChain($application, $from, $to, $publishedAdoption || $development !== null);
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        $fromParameters = $this->parameters($application, (string) $application['application']['version']);
        $instanceVersion = $this->instanceVersion($versionContract);
        $targetParameters = $this->parameters($application, $instanceVersion);
        if ($publishedAdoption) {
            if (array_keys($from->files()) !== array_keys($to->files())) { throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_SET_DIFFERS'); }
            foreach ($to->files() as $path => $file) {
                $old = $from->files()[$path];
                if ($old['mode'] !== $file['mode'] || $old['classification'] !== $file['classification']
                    || $old['template_sha256'] !== hash('sha256', $this->renderArtifact($to, $file, $targetParameters))) {
                    throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_CONTENT_DIFFERS: ' . $path);
                }
            }
        }
        $actions = $this->classify($root, $application, $from, $to, $fromParameters, $targetParameters, $versionContract);
        $pluginProjection = null;
        $pluginTarget = null;
        if (Semver::greaterThanOrEqualTo($from->version(), '3.0.0')) {
            $pluginProjection = $this->pluginProjection($root, sourceApplication: true);
            $targetPlugins = $this->targetPluginIndex($to, $targetParameters, $versionContract);
            $managedUpstreamProofs = $this->managedBundledUpstreamProofs(
                $root,
                $application,
                $pluginProjection,
                $this->targetPluginIndex($from, $fromParameters, $versionContract),
                $from->files(),
                $targetPlugins,
                $to->files(),
            );
            $pluginTarget = $this->upstreamPluginProjection(
                $root,
                $pluginProjection,
                $from->files(),
                $managedUpstreamProofs,
                $targetPlugins,
                $to,
                $targetParameters,
                $versionContract,
            );
            $actions = $this->projectPluginBoundary($actions, $pluginProjection, $targetPlugins, $pluginTarget);
        }
        $summary = $this->summary($actions);
        $impact = $this->withModuleCompatibilityImpact($this->impact($actions), $pluginTarget);
        $appOwnedState = $this->ownershipState($root, $application, 'app-owned');
        $managedState = $this->actionState($root, $actions);
        $identity = [
            'from' => $this->releaseIdentity($from) + ['generation_source' => $application['generation_source'] ?? null],
            'to' => $this->releaseIdentity($to),
            'source_selection' => $sourceSelection,
            'target_generation_source' => $this->targetGenerationSource($application, $to, $sourceSelection),
            'edition' => $to->data['edition'] ?? null,
            'application_version' => $instanceVersion,
            'adoption_application_version' => $application['application']['version'],
            'version_contract' => $versionContract,
            'version_contract_sha256' => $versionContractDigest,
            'from_parameters' => $fromParameters,
            'target_parameters' => $targetParameters,
            'application_manifest_sha256' => $applicationDigest,
            'managed_pre_sha256' => $managedState['digest'],
            'app_owned_pre_sha256' => $appOwnedState['digest'],
            'plugin_projection' => $pluginProjection,
            'plugin_target' => $pluginTarget,
        ];
        if ($development !== null) {
            $identity['source_relation'] = $development['relation'];
            $identity['development_source'] = ['profile' => $application['application']['profile'],
                'workflow' => $workflow['selection'], 'workflow_present' => $workflow['present'],
                'workflow_paths_sha256' => $workflow['paths'] === null ? null : hash('sha256', self::canonicalJson($workflow['paths'])),
                'excluded_paths_sha256' => hash('sha256', self::canonicalJson($development['excluded_paths']))];
        }
        $planDigest = hash('sha256', self::canonicalJson([$identity, $actions]));
        $candidate = 'scaffold-' . substr($planDigest, 0, 24);
        $status = $summary['conflicts'] === 0 && !$this->moduleCompatibilityBlocked($pluginTarget)
            ? 'ready'
            : 'blocked';
        return [
            'schema_version' => 2,
            'protocol' => 'peanut.scaffold-upgrade-plan.v2',
            'candidate' => $candidate,
            'plan_sha256' => 'sha256:' . $planDigest,
            'status' => $status,
            'identity' => $identity,
            'manifest_paths' => ['from' => $from->path, 'to' => $to->path],
            'summary' => $summary,
            'impact' => $impact,
            'managed_pre_state' => $managedState['files'],
            'app_owned_pre_state' => $appOwnedState['files'],
            'actions' => $actions,
        ];
    }

    public function templatePreflight(string $projectRoot, string $toManifestPath, ?string $modeManifestPath = null, ?array $sourceSelection = null): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        return $this->locked($root, function (string $root) use ($toManifestPath, $modeManifestPath, $sourceSelection): array {
            if (isset($sourceSelection['template_package_path'])) {
                (new EditionUpgradePackage())->authenticateTemplate($root, dirname($sourceSelection['template_package_path']));
            }
            $from = ScaffoldManifest::writeRecordedBaseline($root, $modeManifestPath);
            return $this->preflight($root, $from, $toManifestPath, $sourceSelection);
        });
    }

    private function hasRuntimeState(string $root): bool
    {
        foreach (['server/private/installation/installed.json', 'server/private/installation/executing.json'] as $relative) {
            if (file_exists($root . '/' . $relative) || is_link($root . '/' . $relative)) { return true; }
        }
        return false;
    }

    public function preflight(string $projectRoot, string $fromManifestPath, string $toManifestPath, ?array $sourceSelection = null): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $plan = $this->preview($root, $fromManifestPath, $toManifestPath, $sourceSelection);
        if ($this->changesNotificationExecution($plan)) {
            $plan['notification_preflight'] = $this->hasRuntimeState($root)
                ? $this->notificationExecutionPreflight($root)
                : ['status' => 'deferred_until_installation',
                'may_apply_source' => true, 'runtime_accepted' => false,
                'required_before_runtime' => 'peanut module check peanut.notification --runtime-data under the actual stopped/maintenance window'];
            if ($this->hasRuntimeState($root)
                && ($plan['notification_preflight']['may_apply'] ?? false) !== true) {
                $plan['status'] = 'blocked';
            }
        }
        $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
        $path = $stateRoot . '/plans/' . $plan['candidate'] . '.json';
        $this->writeJsonAtomic($path, $plan, 0600);
        $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
        if (!$this->hasEvent($ledger, $plan['candidate'], 'preflight', $plan['status'])) {
            $ledger->append($this->event(
                $plan,
                'preflight',
                $plan['status'],
                $plan['identity']['managed_pre_sha256'],
                null,
            ));
        }
        return $plan + ['plan_path' => $this->relative($root, $path)];
    }

    /** Resolve every blocked managed-file conflict through an explicit preserve/replace decision. */
    public function resolve(
        string $projectRoot,
        string $planPath,
        string $confirmedPlanSha256,
        array $preservePaths,
        array $replacePaths,
    ): array {
        return $this->locked($projectRoot, function (string $root) use ($planPath, $confirmedPlanSha256, $preservePaths, $replacePaths): array {
            $plan = $this->loadPlan($root, $planPath);
            if (($plan['status'] ?? null) !== 'blocked') {
                throw new RuntimeException('SCAFFOLD_RESOLUTION_PLAN_NOT_BLOCKED');
            }
            $this->assertPlanFresh($root, $plan);
            $resolved = $this->resolveConflicts($plan, $confirmedPlanSha256, $preservePaths, $replacePaths);
            if (($resolved['status'] ?? null) !== 'ready') {
                throw new RuntimeException('SCAFFOLD_MODULE_COMPATIBILITY_BLOCKED');
            }
            $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
            $path = $stateRoot . '/plans/' . $resolved['candidate'] . '.json';
            $this->writeJsonAtomic($path, $resolved, 0600);
            $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
            if (!$this->hasEvent($ledger, $resolved['candidate'], 'resolve', 'ready')) {
                $ledger->append($this->event(
                    $resolved,
                    'resolve',
                    'ready',
                    $resolved['identity']['managed_pre_sha256'],
                    null,
                    ['resolution' => $resolved['resolution']],
                ));
            }
            return $resolved + ['plan_path' => $this->relative($root, $path)];
        });
    }

    /** Build a metadata-only ownership-adoption plan from an authenticated formal package. */
    public function adoptionPlan(string $projectRoot, string $packageRoot): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $prepared = (new EditionUpgradePackage())->prepareAdoption($root, $packageRoot);
        $plan = $this->buildAdoptionPlan($root, $prepared, $packageRoot);
        $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
        $path = $stateRoot . '/plans/' . $plan['candidate'] . '.json';
        $this->writeJsonAtomic($path, $plan, 0600);
        $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
        if (!$this->hasEvent($ledger, $plan['candidate'], 'adoption-plan', 'ready')) {
            $ledger->append($this->event($plan, 'adoption-plan', 'ready', $plan['identity']['managed_pre_sha256'], null));
        }
        return $plan + ['plan_path' => $this->relative($root, $path)];
    }

    /** Apply only authenticated old baselines and ownership metadata; live application bytes are never replaced. */
    public function adoptionApply(
        string $projectRoot,
        string $planPath,
        string $confirmedPlanSha256,
        array $confirmedPaths,
    ): array {
        return $this->locked($projectRoot, function (string $root) use ($planPath, $confirmedPlanSha256, $confirmedPaths): array {
            $plan = $this->loadAdoptionPlan($root, $planPath);
            if (!hash_equals($plan['plan_sha256'], $confirmedPlanSha256)
                || array_values($confirmedPaths) !== $plan['paths']) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_CONFIRMATION_MISMATCH');
            }
            $ledger = $this->ledger($root);
            if ($this->candidateState($ledger, $plan['candidate']) === 'adopted') {
                return ['status' => 'adopted', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            $prepared = (new EditionUpgradePackage())->prepareAdoption(
                $root,
                $plan['formal_package']['root'],
            );
            $expected = $this->buildAdoptionPlan(
                $root,
                $prepared,
                $plan['formal_package']['root'],
            );
            if (!hash_equals($plan['candidate'], $expected['candidate'])) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_REBIND_FAILED');
            }
            $recovery = $this->createRecoveryForPaths(
                $root,
                $plan['candidate'],
                $plan['metadata_writes'],
            );
            $ledger->append($this->event($plan, 'adoption-apply', 'started', $plan['identity']['managed_pre_sha256'], null));
            try {
                $writes = 0;
                foreach ($plan['actions'] as $action) {
                    $source = ScaffoldPathGuard::existingFileWithin(
                        $plan['formal_package']['root'],
                        $action['old']['source'],
                        'SCAFFOLD_ADOPTION_SOURCE_INVALID',
                    );
                    $content = file_get_contents($source);
                    if (!is_string($content) || !hash_equals($action['old']['sha256'], hash('sha256', $content))) {
                        throw new RuntimeException('SCAFFOLD_ADOPTION_SOURCE_DRIFT: ' . $action['path']);
                    }
                    $this->writeFileAtomic(
                        ScaffoldPathGuard::projectPath($root, $action['baseline_path']),
                        $content,
                        0644,
                    );
                    $writes++;
                    $this->adoptionFault($writes);
                }
                $manifest = $this->nextAdoptedApplicationManifest($root, $plan);
                $this->writeJsonAtomic(ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json'), $manifest, 0644);
                $writes++;
                $this->adoptionFault($writes);
                $post = $this->managedDigestFromManifest($root, $manifest);
                $ledger->append($this->event($plan, 'adoption-apply', 'adopted', $plan['identity']['managed_pre_sha256'], $post, ['recovery' => $recovery]));
                return ['status' => 'adopted', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'recovery' => $recovery, 'idempotent' => false];
            } catch (Throwable $exception) {
                $ledger->append($this->event($plan, 'adoption-apply', 'failed', $plan['identity']['managed_pre_sha256'], null, ['error' => $exception->getMessage(), 'recovery' => $recovery]));
                throw $exception;
            }
        });
    }

    public function adoptionRecover(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadAdoptionPlan($root, $planPath);
            return $this->recoverCandidate($root, $plan, 'adoption-recover');
        });
    }

    /** Bind an equivalent development source to an already published release without changing application bytes. */
    public function releaseAdoptionPlan(
        string $projectRoot,
        string $releaseManifestPath,
        string $repository,
        string $requestedRef,
        string $channel,
        string $releaseVersion,
        string $sourceCommit,
        string $sourceTree,
        string $releaseLockPath,
        string $releaseLockSha256,
    ): array {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $plan = $this->previewReleaseAdoption(
            $root,
            $releaseManifestPath,
            $repository,
            $requestedRef,
            $channel,
            $releaseVersion,
            $sourceCommit,
            $sourceTree,
            $releaseLockPath,
            $releaseLockSha256,
        );
        $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
        $path = $stateRoot . '/plans/' . $plan['candidate'] . '.json';
        $this->writeJsonAtomic($path, $plan, 0600);
        $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
        if (!$this->hasEvent($ledger, $plan['candidate'], 'release-adoption-plan', 'ready')) {
            $ledger->append($this->event($plan, 'release-adoption-plan', 'ready', $plan['identity']['managed_pre_sha256'], null));
        }
        return $plan + ['plan_path' => $this->relative($root, $path)];
    }

    public function releaseAdoptionApply(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadReleaseAdoptionPlan($root, $planPath);
            $ledger = $this->ledger($root);
            if (in_array($this->candidateState($ledger, $plan['candidate']), ['applied', 'verified'], true)) {
                $this->assertReleaseAdoptionApplied($root, $plan);
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            $proof = $plan['identity']['source_proof'];
            $expected = $this->previewReleaseAdoption(
                $root,
                $plan['manifest_paths']['to'],
                $proof['repository'],
                $proof['requested_ref'],
                $proof['channel'],
                $proof['release_version'],
                $proof['commit'],
                $proof['tree'],
                $proof['release_lock_path'],
                $proof['release_lock_sha256'],
            );
            if ($expected['candidate'] !== $plan['candidate'] || $expected['plan_sha256'] !== $plan['plan_sha256']) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_PLAN_REBIND_FAILED');
            }
            $recovery = $this->createRecoveryForPaths($root, $plan['candidate'], ['.peanut/application-manifest.json']);
            $ledger->append($this->event($plan, 'release-adoption-apply', 'started', $plan['identity']['managed_pre_sha256'], null));
            try {
                [$application] = $this->applicationManifest($root);
                $seal = $plan['identity']['release_seal'];
                $application['template'] = array_diff_key($application['template'], array_flip(['source_identity', 'source_commit', 'source_tree']))
                    + ScaffoldSourceIdentity::fields(ScaffoldSourceIdentity::from($seal));
                $application['template']['inventory_sha256'] = $seal['inventory_sha256'];
                $application['generation_source'] = $plan['identity']['adopted_generation_source'];
                $application['last_release_adoption'] = [
                    'candidate' => $plan['candidate'],
                    'release_version' => $seal['version'],
                    'release_manifest_sha256' => $seal['manifest_sha256'],
                ];
                $this->writeJsonAtomic(ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json'), $application, 0644);
                $this->assertReleaseAdoptionApplied($root, $plan);
                $post = $this->managedDigestFromManifest($root, $application);
                $ledger->append($this->event($plan, 'release-adoption-apply', 'applied', $plan['identity']['managed_pre_sha256'], $post, ['recovery' => $recovery]));
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'recovery' => $recovery, 'idempotent' => false];
            } catch (Throwable $exception) {
                $ledger->append($this->event($plan, 'release-adoption-apply', 'failed', $plan['identity']['managed_pre_sha256'], null, ['error' => $exception->getMessage(), 'recovery' => $recovery]));
                throw $exception;
            }
        });
    }

    public function releaseAdoptionVerify(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadReleaseAdoptionPlan($root, $planPath);
            $ledger = $this->ledger($root);
            if (!in_array($this->candidateState($ledger, $plan['candidate']), ['applied', 'verified'], true)) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_NOT_APPLIED');
            }
            $this->assertReleaseAdoptionApplied($root, $plan);
            if ($this->candidateState($ledger, $plan['candidate']) === 'verified') {
                return ['status' => 'verified', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            [$application] = $this->applicationManifest($root);
            $post = $this->managedDigestFromManifest($root, $application);
            $ledger->append($this->event($plan, 'release-adoption-verify', 'verified', $plan['identity']['managed_pre_sha256'], $post));
            return ['status' => 'verified', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'idempotent' => false];
        });
    }

    public function releaseAdoptionRecover(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadReleaseAdoptionPlan($root, $planPath);
            return $this->recoverCandidate($root, $plan, 'release-adoption-recover');
        });
    }

    /** Apply only the bytes and identities frozen by a fresh, ready plan. */
    public function apply(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $ledger = $this->ledger($root);
            if ($plan['status'] !== 'ready') {
                throw new RuntimeException('SCAFFOLD_PLAN_BLOCKED');
            }
            if (in_array($this->candidateState($ledger, $plan['candidate']), ['applied', 'verified'], true)) {
                $this->assertAppliedState($root, $plan);
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            $this->assertPlanFresh($root, $plan);
            $this->assertPlanRebound($root, $plan);
            $to = $this->planTarget($root, $plan);
            $this->assertManifestDigest($to, $plan['identity']['to']['manifest_sha256']);
            // Installed notification delivery must be drained in the operator's maintenance window.
            if ($this->changesNotificationExecution($plan) && $this->hasRuntimeState($root)) {
                $notification = $this->notificationExecutionPreflight($root);
                if (($notification['may_apply'] ?? false) !== true) {
                    throw new RuntimeException($notification['code']);
                }
            }
            $recovery = $this->createRecovery($root, $plan);
            $pre = $plan['identity']['managed_pre_sha256'];
            $ledger->append($this->event($plan, 'apply', 'started', $pre, null));
            try {
                $writes = 0;
                foreach ($plan['actions'] as $action) {
                    if (!in_array($action['action'], ['rename', 'rename-preserve'], true)) {
                        continue;
                    }
                    $this->assertActionFresh($root, $action);
                    $artifact = $this->targetContent($to, $action, $plan);
                    $targetPath = ScaffoldPathGuard::projectPath($root, $action['path']);
                    if ($action['action'] === 'rename-preserve') {
                        if (($action['rename_preserve'] ?? null) === 'source') {
                            $sourcePath = ScaffoldPathGuard::projectPath($root, (string) $action['rename_from']);
                            $sourceContent = file_get_contents($sourcePath);
                            if (!is_string($sourceContent)) {
                                throw new RuntimeException('SCAFFOLD_RENAME_SOURCE_READ_FAILED: ' . $action['rename_from']);
                            }
                            $this->writeFileAtomic($targetPath, $sourceContent, (int) $action['rename_from_current']['mode']);
                        } elseif (($action['rename_preserve'] ?? null) !== 'target') {
                            throw new RuntimeException('SCAFFOLD_RENAME_PRESERVE_INVALID: ' . $action['path']);
                        }
                    } else {
                        $this->writeFileAtomic($targetPath, $artifact, (int) $action['mode']);
                    }
                    $baseline = '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'];
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $baseline), $artifact, 0644);
                    $sourcePath = ScaffoldPathGuard::projectPath($root, (string) $action['rename_from']);
                    if (file_exists($sourcePath)) {
                        if (!is_file($sourcePath) || is_link($sourcePath) || !unlink($sourcePath)) {
                            throw new RuntimeException('SCAFFOLD_ATOMIC_RENAME_SOURCE_DELETE_FAILED: ' . $action['rename_from']);
                        }
                        $this->pruneEmptyParents(dirname($sourcePath), $root);
                    }
                    $writes++;
                    if ($this->failAfterReplacements !== null && $writes >= $this->failAfterReplacements) {
                        throw new RuntimeException('SCAFFOLD_FAULT_INJECTED');
                    }
                }
                foreach ($plan['actions'] as $action) {
                    if ($action['action'] !== 'delete') {
                        continue;
                    }
                    $this->assertActionFresh($root, $action);
                    $target = ScaffoldPathGuard::projectPath($root, $action['path']);
                    if (!unlink($target)) {
                        throw new RuntimeException('SCAFFOLD_ATOMIC_DELETE_FAILED: ' . $action['path']);
                    }
                    $this->pruneEmptyParents(dirname($target), $root);
                    $writes++;
                    if ($this->failAfterReplacements !== null && $writes >= $this->failAfterReplacements) {
                        throw new RuntimeException('SCAFFOLD_FAULT_INJECTED');
                    }
                }
                foreach ($plan['actions'] as $action) {
                    if (!in_array($action['action'], ['create', 'replace', 'regenerate'], true)) {
                        continue;
                    }
                    $this->assertActionFresh($root, $action);
                    $artifact = $this->targetContent($to, $action, $plan);
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $action['path']), $artifact, (int) $action['mode']);
                    $baseline = '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'];
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $baseline), $artifact, 0644);
                    $writes++;
                    if ($this->failAfterReplacements !== null && $writes >= $this->failAfterReplacements) {
                        throw new RuntimeException('SCAFFOLD_FAULT_INJECTED');
                    }
                }
                foreach ($plan['actions'] as $action) {
                    if ($action['action'] !== 'preserve') {
                        continue;
                    }
                    $this->assertActionFresh($root, $action);
                    $artifact = $this->baselineContent($root, $to, $action, $plan);
                    $baseline = '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'];
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $baseline), $artifact, 0644);
                }
                $this->assertPluginProjection($root, $plan, true);
                $manifest = $this->nextApplicationManifest($root, $plan, $to);
                $this->writeJsonAtomic(ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json'), $manifest, 0644);
                $post = $this->managedDigestFromManifest($root, $manifest);
                $ledger->append($this->event($plan, 'apply', 'applied', $pre, $post, ['recovery' => $recovery]));
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'recovery' => $recovery, 'idempotent' => false];
            } catch (Throwable $exception) {
                $ledger->append($this->event($plan, 'apply', 'failed', $pre, null, ['error' => $exception->getMessage(), 'recovery' => $recovery]));
                throw $exception;
            }
        });
    }

    /** Verify the target scaffold and its newly adopted current-version snapshot. */
    public function verify(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $ledger = $this->ledger($root);
            if ($this->candidateState($ledger, $plan['candidate']) === 'verified') {
                $this->assertAppliedState($root, $plan);
                return ['status' => 'verified', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            if (!in_array($this->candidateState($ledger, $plan['candidate']), ['applied','verified'], true)) {
                throw new RuntimeException('SCAFFOLD_APPLY_NOT_COMMITTED');
            }
            [$application, $actualAppOwned] = $this->assertAppliedState($root, $plan);
            $post = $this->managedDigestFromManifest($root, $application);
            $ledger->append($this->event($plan, 'verify', 'verified', $plan['identity']['managed_pre_sha256'], $post));
            return ['status' => 'verified', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'app_owned_sha256' => $actualAppOwned['digest'], 'idempotent' => false];
        });
    }

    public function recover(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $this->retainedUpstreamDeletions($root, $plan);
            $ledger = $this->ledger($root);
            $manifestPath = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json');
            if (!is_file($manifestPath)) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_NOT_FOUND');
            }
            $recovery = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($recovery) || ($recovery['candidate'] ?? null) !== $plan['candidate'] || !is_array($recovery['files'] ?? null)
                || !hash_equals((string) ($recovery['pre_tree_sha256'] ?? ''), 'sha256:' . hash('sha256', self::canonicalJson($recovery['files'])))) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_INVALID');
            }
            $already = $this->recoveryMatches($root, $recovery);
            if (!$already) {
                foreach ($recovery['files'] as $relative => $state) {
                    $target = ScaffoldPathGuard::projectPath($root, (string) $relative);
                    if ($state['present']) {
                        $backup = ScaffoldPathGuard::existingFileWithin(dirname($manifestPath), dirname($manifestPath) . '/' . $state['backup'], 'SCAFFOLD_RECOVERY_BACKUP_INVALID');
                        $content = file_get_contents($backup);
                        if (!is_string($content) || !hash_equals($state['sha256'], hash('sha256', $content))
                            || (fileperms($backup) & 0777) !== 0600) {
                            throw new RuntimeException('SCAFFOLD_RECOVERY_BACKUP_DRIFT');
                        }
                        $this->writeFileAtomic($target, $content, (int) $state['mode']);
                    } elseif (file_exists($target)) {
                        if (!is_file($target) || is_link($target)) {
                            throw new RuntimeException('SCAFFOLD_RECOVERY_PATH_COLLISION: ' . $relative);
                        }
                        unlink($target);
                        $this->pruneEmptyParents(dirname($target), $root);
                    }
                }
                if (!$this->recoveryMatches($root, $recovery)) {
                    throw new RuntimeException('SCAFFOLD_RECOVERY_VERIFY_FAILED');
                }
            }
            $this->assertPluginProjection($root, $plan);
            if (!$this->hasEvent($ledger, $plan['candidate'], 'recover', 'recovered')) {
                $ledger->append($this->event($plan, 'recover', 'recovered', $plan['identity']['managed_pre_sha256'], $plan['identity']['managed_pre_sha256']));
            }
            return ['status' => 'recovered', 'candidate' => $plan['candidate'], 'tree_sha256' => $recovery['pre_tree_sha256'], 'idempotent' => $already];
        });
    }

    /** Verify an already recovered tree without rewriting any application file. */
    public function verifyRecovery(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $this->retainedUpstreamDeletions($root, $plan);
            $manifestPath = ScaffoldPathGuard::projectPath(
                $root,
                '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json',
            );
            if (!is_file($manifestPath) || is_link($manifestPath)) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_NOT_FOUND');
            }
            $recovery = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($recovery) || ($recovery['candidate'] ?? null) !== $plan['candidate']
                || !is_array($recovery['files'] ?? null)
                || !hash_equals(
                    (string) ($recovery['pre_tree_sha256'] ?? ''),
                    'sha256:' . hash('sha256', self::canonicalJson($recovery['files'])),
                )
                || !$this->recoveryMatches($root, $recovery)) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_VERIFY_FAILED');
            }
            $this->assertPluginProjection($root, $plan);
            return [
                'status' => 'recovered',
                'candidate' => $plan['candidate'],
                'tree_sha256' => $recovery['pre_tree_sha256'],
                'read_only' => true,
            ];
        });
    }

    private function previewReleaseAdoption(
        string $root,
        string $releaseManifestPath,
        string $repository,
        string $requestedRef,
        string $channel,
        string $releaseVersion,
        string $sourceCommit,
        string $sourceTree,
        string $releaseLockPath,
        string $releaseLockSha256,
    ): array {
        if ($repository !== 'peanut-business/peanut-admin-code'
            || !is_string($requestedRef) || $requestedRef === '' || strlen($requestedRef) > 255
            || preg_match('/[\x00-\x20\x7f]/', $requestedRef) === 1
            || !in_array($channel, ['prerelease', 'stable'], true)
            || preg_match(self::STRICT_SEMVER, $releaseVersion) !== 1
            || ($channel === 'stable' && str_contains($releaseVersion, '-'))
            || ($channel === 'prerelease' && !str_contains($releaseVersion, '-'))
            || preg_match('/^[a-f0-9]{40}$/D', $sourceCommit) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $sourceTree) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $releaseLockSha256) !== 1) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_PROOF_INVALID');
        }
        $release = ScaffoldManifest::load($releaseManifestPath);
        $sourceRoot = realpath(dirname($release->directory, 3));
        if (!is_string($sourceRoot)
            || $release->path !== $sourceRoot . '/scaffold/releases/v' . $releaseVersion . '/scaffold-manifest.json'
            || $release->version() !== $releaseVersion
            || ($release->release()['inventory_template_version'] ?? null) !== $releaseVersion
            || ($release->data['edition'] ?? null) !== null) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_MANIFEST_INVALID');
        }
        $this->validatePublishedReleaseLock(
            $root,
            $releaseLockPath,
            $releaseLockSha256,
            $releaseVersion,
            $sourceCommit,
            $sourceTree,
            $release->release()['inventory_sha256'],
            $release->digest(),
        );
        $lockPath = ScaffoldPathGuard::existingFileWithin($root, $releaseLockPath, 'SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        if (!str_starts_with($lockPath, $root . '/.peanut/upgrades/release-proofs/')) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        }
        if ($release->sourceIdentity()['kind'] !== 'git') { throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_GIT_SOURCE_REQUIRED'); }
        if ($this->releaseGit($sourceRoot, ['status', '--porcelain=v1', '--untracked-files=all']) !== ''
            || $this->releaseGit($sourceRoot, ['rev-parse', 'HEAD']) !== $sourceCommit
            || $this->releaseGit($sourceRoot, ['rev-parse', 'HEAD^{tree}']) !== $sourceTree
            || $this->releaseGit($sourceRoot, ['rev-parse', $release->release()['source_commit'] . '^{tree}']) !== $release->release()['source_tree']) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_SOURCE_CHANGED');
        }
        $origin = $this->releaseGit($sourceRoot, ['remote', 'get-url', 'origin']);
        if (!in_array($origin, [
            'git@github.com:peanut-business/peanut-admin-code.git',
            'https://github.com/peanut-business/peanut-admin-code.git',
            'https://github.com/peanut-business/peanut-admin-code',
        ], true)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_REPOSITORY_MISMATCH');
        }
        $this->releaseGit($sourceRoot, ['merge-base', '--is-ancestor', $release->release()['source_commit'], $sourceCommit]);
        $inventoryDigest = hash_file('sha256', $sourceRoot . '/scaffold/application-template-inventory.json');
        if (!is_string($inventoryDigest) || !hash_equals($release->release()['inventory_sha256'], $inventoryDigest)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_INVENTORY_MISMATCH');
        }
        foreach ($release->files() as $path => $file) {
            $artifact = $release->artifactPath($file);
            $digest = hash_file('sha256', $artifact);
            if (!is_string($digest) || !hash_equals((string) $file['template_sha256'], $digest)
                || (fileperms($artifact) & 0777) !== $file['mode']) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_ARTIFACT_CHANGED: ' . $path);
            }
        }
        [$application, $applicationDigest] = $this->applicationManifest($root);
        $generation = $application['generation_source'] ?? null;
        if (!is_array($generation)
            || ($generation['repository'] ?? null) !== $repository
            || !is_string($generation['requested_ref'] ?? null)
            || $generation['requested_ref'] === ''
            || !array_key_exists('release_version', $generation)
            || $generation['release_version'] !== null
            || ($generation['channel'] ?? null) !== 'development'
            || preg_match('/^[a-f0-9]{40}$/D', (string) ($generation['commit'] ?? '')) !== 1
            || ($generation['tree'] ?? null) !== $sourceTree
            || ($generation['inventory_sha256'] ?? null) !== $inventoryDigest
            || ($application['template']['version'] ?? null) !== $releaseVersion
            || ($application['template']['inventory_sha256'] ?? null) !== $inventoryDigest
            || ($application['edition']['source_sha256'] ?? null) !== ($generation['edition_profile_sha256'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_CONTENT_DIFFERS');
        }
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        $releaseFiles = $release->files();
        $appManagedFiles = [];
        foreach ($application['files'] as $file) {
            if (in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                $appManagedFiles[$file['path']] = $file;
            }
        }
        if (count($releaseFiles) !== count($appManagedFiles)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_SET_DIFFERS');
        }
        foreach ($releaseFiles as $path => $releasedFile) {
            $owned = $appManagedFiles[$path] ?? null;
            if (!is_array($owned)
                || ($owned['classification'] ?? null) !== $releasedFile['classification']
                || ($owned['mode'] ?? null) !== $releasedFile['mode']) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_METADATA_DIFFERS: ' . $path);
            }
        }
        $managed = $this->releaseManagedState($root, $application);
        $baseline = $this->releaseBaselineState($root, $application);
        $appOwned = $this->ownershipState($root, $application, 'app-owned');
        $adoptedGeneration = $generation;
        $adoptedGeneration['repository'] = $repository;
        $adoptedGeneration['requested_ref'] = $requestedRef;
        $adoptedGeneration['channel'] = $channel;
        $adoptedGeneration['release_version'] = $releaseVersion;
        $adoptedGeneration['commit'] = $sourceCommit;
        $adoptedGeneration['tree'] = $sourceTree;
        $adoptedGeneration['source_identity'] = ['kind' => 'git', 'commit' => $sourceCommit, 'tree' => $sourceTree];
        $identity = [
            'kind' => 'release-adoption',
            'metadata_writes' => ['.peanut/application-manifest.json'],
            'from' => ['version' => $application['template']['version'], 'generation_source' => $generation],
            'to' => ['version' => $releaseVersion, 'release_version' => $releaseVersion,
                'source_identity' => ['kind' => 'git', 'commit' => $sourceCommit, 'tree' => $sourceTree], 'source_commit' => $sourceCommit,
                'source_tree' => $sourceTree, 'inventory_sha256' => $inventoryDigest],
            'release_seal' => ['version' => $releaseVersion, 'source_identity' => $release->sourceIdentity(), 'source_commit' => $release->release()['source_commit'],
                'source_tree' => $release->release()['source_tree'], 'inventory_sha256' => $inventoryDigest,
                'manifest_sha256' => $release->digest()],
            'source_proof' => ['repository' => $repository, 'requested_ref' => $requestedRef, 'channel' => $channel,
                'release_version' => $releaseVersion, 'commit' => $sourceCommit, 'tree' => $sourceTree,
                'release_lock_path' => $lockPath, 'release_lock_sha256' => $releaseLockSha256],
            'adopted_generation_source' => $adoptedGeneration,
            'application_manifest_sha256' => $applicationDigest,
            'version_contract_sha256' => $versionContractDigest,
            'version_contract' => $versionContract,
            'application_identity' => $application['application'],
            'edition' => $application['edition'],
            'files_sha256' => 'sha256:' . hash('sha256', self::canonicalJson($application['files'])),
            'digests' => $application['digests'],
            'managed_pre_sha256' => $managed['digest'],
            'baseline_pre_sha256' => $baseline['digest'],
            'app_owned_pre_sha256' => $appOwned['digest'],
        ];
        $digest = hash('sha256', self::canonicalJson([$identity, []]));
        return [
            'schema_version' => 2, 'protocol' => 'peanut.scaffold-upgrade-plan.v2',
            'candidate' => 'scaffold-' . substr($digest, 0, 24), 'plan_sha256' => 'sha256:' . $digest,
            'status' => 'ready', 'identity' => $identity,
            'manifest_paths' => ['to' => $release->path], 'summary' => $this->summary([]),
            'impact' => $this->impact([]), 'managed_pre_state' => $managed['files'],
            'baseline_pre_state' => $baseline['files'], 'app_owned_pre_state' => $appOwned['files'],
            'actions' => [],
        ];
    }

    private function validatePublishedReleaseLock(
        string $root,
        string $releaseLockPath,
        string $releaseLockSha256,
        string $releaseVersion,
        string $sourceCommit,
        string $sourceTree,
        string $inventorySha256,
        ?string $manifestSha256,
    ): string {
        $lockPath = ScaffoldPathGuard::existingFileWithin($root, $releaseLockPath, 'SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        if (!str_starts_with($lockPath, $root . '/.peanut/upgrades/release-proofs/')) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        }
        $lockRaw = file_get_contents($lockPath);
        if (!is_string($lockRaw) || !hash_equals($releaseLockSha256, 'sha256:' . hash('sha256', $lockRaw))) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_CHANGED');
        }
        try {
            $lock = json_decode($lockRaw, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID', 0, $exception);
        }
        if (!is_array($lock) || ($lock['schema_version'] ?? null) !== 1
            || ($lock['protocol'] ?? null) !== 'peanut.release-candidate-lock.v1'
            || ($lock['version'] ?? null) !== $releaseVersion
            || ($lock['tag'] ?? null) !== 'v' . $releaseVersion
            || ($lock['candidate']['commit'] ?? null) !== $sourceCommit
            || ($lock['candidate']['tree'] ?? null) !== $sourceTree
            || ($lock['inputs']['application_template_inventory_sha256'] ?? null) !== $inventorySha256
            || ($manifestSha256 !== null
                && ($lock['inputs']['scaffold_manifest_sha256'] ?? null) !== substr($manifestSha256, 7))) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        }
        return $lockPath;
    }

    private function releaseGit(string $root, array $arguments): string
    {
        $pipes = [];
        $process = proc_open(['git', '-C', $root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_GIT_UNAVAILABLE');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($output)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_GIT_IDENTITY_INVALID');
        }
        return trim($output);
    }

    private function releaseManagedState(string $root, array $application): array
    {
        $files = [];
        foreach ($application['files'] as $file) {
            if (in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                $path = (string) $file['path'];
                $files[$path] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function releaseBaselineState(string $root, array $application): array
    {
        $files = [];
        foreach ($application['files'] as $file) {
            if (!in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                continue;
            }
            $path = (string) ($file['baseline_path'] ?? '');
            $expected = '.peanut/scaffold-baseline/' . $application['template']['version'] . '/files/' . $file['path'];
            if ($path !== $expected) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_INVALID: ' . $file['path']);
            }
            $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
            if (!$state['present'] || $state['mode'] !== 0644
                || !hash_equals((string) ($file['baseline_sha256'] ?? ''), (string) $state['sha256'])) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_CHANGED: ' . $file['path']);
            }
            $files[$path] = $state;
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function loadReleaseAdoptionPlan(string $root, string $path): array
    {
        $plan = $this->loadPlan($root, $path);
        if (($plan['identity']['kind'] ?? null) !== 'release-adoption' || $plan['actions'] !== []
            || ($plan['status'] ?? null) !== 'ready'
            || !is_array($plan['managed_pre_state'] ?? null)
            || !is_array($plan['baseline_pre_state'] ?? null)
            || !is_array($plan['app_owned_pre_state'] ?? null)
            || !is_array($plan['identity']['source_proof'] ?? null)
            || ($plan['identity']['managed_pre_sha256'] ?? null) !== 'sha256:' . hash('sha256', self::canonicalJson($plan['managed_pre_state']))
            || ($plan['identity']['baseline_pre_sha256'] ?? null) !== 'sha256:' . hash('sha256', self::canonicalJson($plan['baseline_pre_state']))
            || ($plan['identity']['app_owned_pre_sha256'] ?? null) !== 'sha256:' . hash('sha256', self::canonicalJson($plan['app_owned_pre_state']))) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_PLAN_INVALID');
        }
        return $plan;
    }

    private function assertReleaseAdoptionApplied(string $root, array $plan): void
    {
        [$application] = $this->applicationManifest($root);
        $seal = $plan['identity']['release_seal'];
        if (($application['template']['version'] ?? null) !== $seal['version']
            || ScaffoldSourceIdentity::from($application['template'] ?? []) !== ScaffoldSourceIdentity::from($seal)
            || ($application['template']['inventory_sha256'] ?? null) !== $seal['inventory_sha256']
            || ($application['generation_source'] ?? null) !== $plan['identity']['adopted_generation_source']
            || ($application['last_release_adoption']['candidate'] ?? null) !== $plan['candidate']
            || ($application['last_release_adoption']['release_manifest_sha256'] ?? null) !== $seal['manifest_sha256']
            || ($application['application'] ?? null) !== $plan['identity']['application_identity']
            || ($application['edition'] ?? null) !== $plan['identity']['edition']
            || ($application['digests'] ?? null) !== $plan['identity']['digests']
            || 'sha256:' . hash('sha256', self::canonicalJson($application['files'])) !== $plan['identity']['files_sha256']
            || $this->versionContract($root, $application) !== [$plan['identity']['version_contract'], $plan['identity']['version_contract_sha256']]
            || $this->releaseManagedState($root, $application)['digest'] !== $plan['identity']['managed_pre_sha256']
            || $this->releaseBaselineState($root, $application)['digest'] !== $plan['identity']['baseline_pre_sha256']
            || $this->ownershipState($root, $application, 'app-owned')['digest'] !== $plan['identity']['app_owned_pre_sha256']) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_VERIFY_FAILED');
        }
    }

    private function validateUpgradeSourceSelection(string $root, ?array $selection, ScaffoldManifest $to): ?array
    {
        if ($selection === null) {
            if ($to->sourceIdentity()['kind'] === 'local') { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PACKAGE_REQUIRED'); }
            return null;
        }
        if ($to->sourceIdentity()['kind'] === 'local' && !isset($selection['template_package_path'])) {
            throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PACKAGE_REQUIRED');
        }
        if (isset($selection['template_package_path'])) {
            $sourceIdentity = ScaffoldSourceIdentity::from($selection, true);
            $local = $sourceIdentity['kind'] === 'local';
            $expectedKeys = array_merge(['repository', 'requested_ref', 'channel'],
                $local ? ['source_identity'] : ['commit', 'tree'], ['template_package_path', 'template_package_sha256']);
            if (!$local && isset($selection['source_identity'])) { $expectedKeys[] = 'source_identity'; }
            $actualKeys = array_keys(array_diff_key($selection, ['source_checkout' => true]));
            sort($expectedKeys, SORT_STRING); sort($actualKeys, SORT_STRING);
            if ($actualKeys !== $expectedKeys
                || (isset($selection['source_checkout']) && $selection['channel'] !== 'development')
                || (!$local && $selection['repository'] !== 'peanut-business/peanut-admin-code')
                || ($local && ($selection['channel'] !== 'development'
                    || ($selection['repository'] !== null && !is_string($selection['repository']))))
                || !in_array($selection['channel'], ['development', 'stable', 'prerelease'], true)
                || ($selection['channel'] !== 'development' && ($selection['requested_ref'] !== 'v' . $to->version()
                    || ($selection['channel'] === 'stable') === str_contains($to->version(), '-')))
                || preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $selection['template_package_sha256']) !== 1) {
                throw new RuntimeException('SCAFFOLD_TEMPLATE_SOURCE_SELECTION_INVALID');
            }
            $file = ScaffoldPathGuard::existingFileWithin($root, $selection['template_package_path'], 'SCAFFOLD_TEMPLATE_SOURCE_METADATA_INVALID');
            if ('sha256:' . hash_file('sha256', $file) !== $selection['template_package_sha256']) { throw new RuntimeException('SCAFFOLD_TEMPLATE_SOURCE_METADATA_CHANGED'); }
            $metadata = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (($metadata['protocol'] ?? null) !== 'peanut.template-package.v1'
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($metadata['archive']['sha256'] ?? '')) !== 1
                || $file !== $root . '/.peanut/upgrades/template-packages/' . $metadata['archive']['sha256'] . '/template-package.json'
                || $to->path !== dirname($file) . '/scaffold-manifest.json'
                || $to->digest() !== 'sha256:' . ($metadata['scaffold_manifest_sha256'] ?? '')
                || ($metadata['version'] ?? null) !== $to->version() || ($metadata['edition'] ?? null) !== ($to->data['edition']['name'] ?? null)
                || ($metadata['source']['repository'] ?? null) !== $selection['repository']
                || ScaffoldSourceIdentity::from($metadata['source'] ?? [], true) !== $sourceIdentity || $sourceIdentity !== $to->sourceIdentity()
                || ($metadata['source']['inventory_sha256'] ?? null) !== $to->release()['inventory_sha256']) {
                throw new RuntimeException('SCAFFOLD_TEMPLATE_SOURCE_IDENTITY_MISMATCH');
            }
            if ($selection['channel'] === 'development' && $selection['requested_ref'] !== 'local:' . ($local ? $sourceIdentity['snapshot_sha256'] : $metadata['archive']['sha256'])) {
                throw new RuntimeException('SCAFFOLD_TEMPLATE_LOCAL_SOURCE_INVALID');
            }
            return $selection;
        }
        if (array_keys($selection) !== ['repository', 'requested_ref', 'channel', 'commit', 'tree', 'release_lock_path', 'release_lock_sha256']
            || $selection['repository'] !== 'peanut-business/peanut-admin-code'
            || !is_string($selection['requested_ref']) || $selection['requested_ref'] === ''
            || strlen($selection['requested_ref']) > 255
            || preg_match('/[\x00-\x20\x7f]/', $selection['requested_ref']) === 1
            || !in_array($selection['channel'], ['prerelease', 'stable'], true)
            || ($selection['channel'] === 'stable' && str_contains($to->version(), '-'))
            || ($selection['channel'] === 'prerelease' && !str_contains($to->version(), '-'))
            || preg_match('/^[a-f0-9]{40}$/D', (string) $selection['commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', (string) $selection['tree']) !== 1
            || !is_string($selection['release_lock_path'])
            || preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $selection['release_lock_sha256']) !== 1) {
            throw new RuntimeException('SCAFFOLD_UPGRADE_SOURCE_SELECTION_INVALID');
        }
        $selection['release_lock_path'] = $this->validatePublishedReleaseLock(
            $root,
            $selection['release_lock_path'],
            $selection['release_lock_sha256'],
            $to->version(),
            $selection['commit'],
            $selection['tree'],
            $to->release()['inventory_sha256'],
            null,
        );
        return $selection;
    }

    /** Read-only presence rule matching EditionProjector::workflow's registered file projection. */
    private function localDevelopmentSource(array $base, ScaffoldManifest $to, array $selection, string $profile, string $workflow, string $fromVersion, ?array $workflowPaths): array
    {
        $baseIdentity = ScaffoldSourceIdentity::from($base, true);
        $targetIdentity = $to->sourceIdentity();
        if (($base['channel'] ?? null) !== 'development' || !array_key_exists('release_version', $base) || $base['release_version'] !== null
            || ($selection['channel'] ?? null) !== 'development' || !isset($selection['template_package_path'])
            || !in_array($profile, ['minimal', 'standard', 'full'], true) || !in_array($workflow, ['none', 'runtime', 'full'], true)
            || Semver::compare($fromVersion, $to->version()) > 0) { throw new RuntimeException('SCAFFOLD_LOCAL_DEVELOPMENT_SOURCE_INVALID'); }
        $metadata = json_decode((string) file_get_contents($selection['template_package_path']), true, 512, JSON_THROW_ON_ERROR);
        $directory = dirname($selection['template_package_path']);
        $proofPath = ScaffoldPathGuard::existingFileWithin($directory, $directory . '/source-snapshot.json', 'SCAFFOLD_LOCAL_SOURCE_PROOF_REQUIRED');
        if (filesize($proofPath) > 33554432 || hash_file('sha256', $proofPath) !== ($metadata['source_snapshot_sha256'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_CHANGED');
        }
        $proof = json_decode((string) file_get_contents($proofPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($proof)) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_INVALID'); }
        $inputs = ScaffoldSourceIdentity::validateProof($proof, $targetIdentity);
        $inventory = $inputs['inventory'];
        $target = $metadata['source'] ?? [];
        if (isset($selection['source_checkout']) && ScaffoldSourceIdentity::local($selection['source_checkout']) !== $targetIdentity) {
            throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_CHECKOUT_CHANGED');
        }
        foreach (['inventory' => ['inventory_base64', $to->release()['inventory_sha256']],
            'edition_profile' => ['edition_profile_base64', $to->data['edition']['source_sha256'] ?? null]] as [$key, $expected]) {
            if (hash('sha256', base64_decode($proof[$key], true)) !== $expected) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INPUT_CHANGED'); }
        }
        if (($inventory['template_version'] ?? null) !== $to->version()
            || ScaffoldSourceIdentity::from($target, true) !== $targetIdentity
            || ($target['channel'] ?? null) !== 'development' || !array_key_exists('release_version', $target) || $target['release_version'] !== null
            || ($target['inventory_sha256'] ?? null) !== $to->release()['inventory_sha256']
            || ($target['edition_profile_sha256'] ?? null) !== ($to->data['edition']['source_sha256'] ?? null)
            || ($metadata['workflow'] ?? null) !== $workflow || ($to->release()['workflow'] ?? null) !== $workflow
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($base['inventory_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($base['edition_profile_sha256'] ?? '')) !== 1
            || !in_array($metadata['profile'] ?? null, ['minimal', 'standard', 'full'], true)) {
            throw new RuntimeException('SCAFFOLD_LOCAL_DEVELOPMENT_SOURCE_INVALID');
        }
        $createPath = ScaffoldPathGuard::existingFileWithin($directory, $directory . '/template-create.json', 'SCAFFOLD_LOCAL_PAYLOAD_REQUIRED');
        if (filesize($createPath) > 33554432 || hash_file('sha256', $createPath) !== ($metadata['create_manifest_sha256'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_LOCAL_PAYLOAD_CHANGED');
        }
        $create = json_decode((string) file_get_contents($createPath), true, 512, JSON_THROW_ON_ERROR);
        if (($create['protocol'] ?? null) !== 'peanut.template-create.v1' || !is_array($create['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_LOCAL_PAYLOAD_INVALID');
        }
        $payload = []; $seen = []; $managed = $to->files();
        foreach ($create['files'] as $file) {
            $path = ScaffoldManifest::path((string) ($file['path'] ?? ''));
            $source = (($file['classification'] ?? null) === 'app-owned' ? 'defaults/' : 'files/') . $path;
            if (isset($seen[$path]) || ($file['source'] ?? null) !== $source || !in_array($file['mode'] ?? null, [0644, 0755], true)
                || !in_array($file['classification'] ?? null, ['managed', 'generated-managed', 'app-owned'], true)) {
                throw new RuntimeException('SCAFFOLD_LOCAL_PAYLOAD_INVALID');
            }
            $seen[$path] = true;
            $actual = ScaffoldPathGuard::existingFileWithin($directory, ScaffoldPathGuard::projectPath($directory, $source), 'SCAFFOLD_LOCAL_PAYLOAD_REQUIRED');
            if (hash_file('sha256', $actual) !== ($file['sha256'] ?? null) || (fileperms($actual) & 0777) !== $file['mode']) {
                throw new RuntimeException('SCAFFOLD_LOCAL_PAYLOAD_CHANGED');
            }
            if ($file['classification'] !== 'app-owned') {
                $entry = $managed[$path] ?? [];
                if (($entry['template_sha256'] ?? null) !== $file['sha256'] || ($entry['mode'] ?? null) !== $file['mode']
                    || ($entry['classification'] ?? null) !== $file['classification']) { throw new RuntimeException('SCAFFOLD_LOCAL_PAYLOAD_MANIFEST_MISMATCH'); }
                unset($managed[$path]);
            }
            $payload[] = array_intersect_key($file, array_flip(['path', 'mode', 'sha256', 'classification']));
        }
        if ($managed !== [] || self::canonicalJson($payload) !== self::canonicalJson($proof['payload_files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_LOCAL_PAYLOAD_MANIFEST_MISMATCH');
        }
        $excluded = [];
        foreach ($inventory['files'] as $row) {
            if (!is_array($row['profiles'] ?? null) || !is_string($row['target'] ?? null)
                || array_diff($row['profiles'], ['minimal', 'standard', 'full']) !== []) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INVENTORY_INVALID'); }
            if (($row['classification'] ?? null) !== 'excluded' && in_array($profile, $row['profiles'], true)
                && !in_array($metadata['profile'], $row['profiles'], true)) { throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PROFILE_NOT_CONTAINED'); }
            if (!in_array($profile, $row['profiles'], true)) { $excluded[] = $row['target']; }
        }
        if ($workflowPaths !== null) {
            // Legacy APPs do not declare a workflow selection. Its real baseline
            // remains the authority; do not infer a different workflow from source.
            foreach ($workflowPaths as $path) {
                if ($this->workflowPathSelected($path, $workflow) !== true) { throw new RuntimeException('SCAFFOLD_DEVELOPMENT_WORKFLOW_BASELINE_MISMATCH'); }
            }
        }
        sort($excluded, SORT_STRING);
        return ['relation' => ['kind' => 'local_snapshot', 'base' => $baseIdentity, 'target' => $targetIdentity,
            'base_inventory_sha256' => $base['inventory_sha256'], 'target_inventory_sha256' => $target['inventory_sha256']],
            'excluded_paths' => $excluded];
    }

    /** Read-only presence rule matching EditionProjector::workflow's registered file projection. */
    private function workflowPathSelected(string $path, string $workflow): ?bool
    {
        $belongs = str_starts_with($path, 'server/app/modules/peanut/workflow/')
            || str_starts_with($path, 'web/src/modules/peanut-workflow/') || str_starts_with($path, 'plugins/peanut.workflow/')
            || str_starts_with($path, 'server/app/modules/peanut/article/src/Integration/Workflow/')
            || str_starts_with($path, 'server/app/modules/peanut/article/resources/workflows/')
            || str_starts_with($path, 'web/src/modules/peanut-article/workflow/')
            || str_starts_with($path, 'web/src/modules/peanut-article/views/review/')
            || $path === 'server/app/command/WorkflowInstallManaged.php';
        if (!$belongs) { return null; }
        $design = str_starts_with($path, 'web/src/modules/peanut-workflow/designer/')
            || in_array($path, ['server/app/modules/peanut/workflow/route/design.php',
                'server/app/modules/peanut/workflow/src/Controller/WorkflowDefinitionController.php',
                'server/app/modules/peanut/workflow/src/Application/WorkflowDefinitionService.php',
                'server/app/modules/peanut/workflow/src/Http/WorkflowJsonProjection.php'], true);
        return $workflow !== 'none' && ($workflow !== 'runtime' || !$design);
    }

    private function recordedWorkflowPaths(ScaffoldManifest $from): array
    {
        $paths = array_values(array_filter(array_keys($from->files()),
            fn(string $path): bool => $this->workflowPathSelected($path, 'full') !== null));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** Absence stays absent in APP identity; the target selection still needs baseline/Git proof. */
    private function developmentWorkflow(array $application, ScaffoldManifest $from, ScaffoldManifest $to): array
    {
        $present = array_key_exists('workflow', $application);
        $workflow = $present ? $application['workflow'] : ($to->release()['workflow'] ?? null);
        if (!is_string($workflow) || !in_array($workflow, ['none', 'runtime', 'full'], true)
            || !$from->isRecordedBaseline()) {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_WORKFLOW_INVALID');
        }
        return ['present' => $present, 'selection' => $workflow,
            'paths' => $present ? null : $this->recordedWorkflowPaths($from)];
    }

    /** Prove immutable development ancestry locally; caller JSON is never source authority. */
    private function developmentSource(array $base, ScaffoldManifest $to, array $selection, string $profile, string $workflow, string $fromVersion, ?array $workflowPaths = null): array
    {
        if ($to->sourceIdentity()['kind'] === 'local') {
            return $this->localDevelopmentSource($base, $to, $selection, $profile, $workflow, $fromVersion, $workflowPaths);
        }
        $repository = 'peanut-business/peanut-admin-code';
        $checkout = $selection['source_checkout'] ?? null;
        if (!is_string($checkout) || !str_starts_with($checkout, '/') || realpath($checkout) !== $checkout
            || !is_dir($checkout) || is_link($checkout) || ($selection['channel'] ?? null) !== 'development'
            || !isset($selection['template_package_path']) || ($base['repository'] ?? null) !== $repository
            || ($base['channel'] ?? null) !== 'development' || !array_key_exists('release_version', $base)
            || $base['release_version'] !== null || $fromVersion !== $to->version()
            || !in_array($profile, ['minimal', 'standard', 'full'], true)
            || !in_array($workflow, ['none', 'runtime', 'full'], true)) {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_SOURCE_INVALID');
        }
        $git = function (array $args, bool $ancestry = false) use ($checkout): string {
            // Explicit environment prevents inherited Git object/config overrides.
            $environment = ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C',
                'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_NO_REPLACE_OBJECTS' => '1', 'GIT_NO_LAZY_FETCH' => '1'];
            $pipes = [];
            $process = proc_open(array_merge(['git', '--no-replace-objects', '-C', $checkout], $args),
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
            if (!is_resource($process)) { throw new RuntimeException('SCAFFOLD_DEVELOPMENT_GIT_UNAVAILABLE'); }
            $output = stream_get_contents($pipes[1], 8388609); $error = stream_get_contents($pipes[2], 65537);
            fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
            if ($code !== 0 || $error !== '' || strlen($output) > 8388608) {
                throw new RuntimeException($ancestry ? 'SCAFFOLD_DEVELOPMENT_NOT_SUCCESSOR' : 'SCAFFOLD_DEVELOPMENT_GIT_REJECTED');
            }
            return $output;
        };
        if (trim($git(['rev-parse', '--show-toplevel'])) !== $checkout
            || preg_match('/^(extensions\\.partialclone|remote\\.[^=]+\\.promisor)=/mi', $git(['config', '--local', '--list'])) === 1) {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_REPOSITORY_INVALID');
        }
        $origin = trim($git(['config', '--get', 'remote.origin.url']));
        if (!in_array($origin, ['git@github.com:peanut-business/peanut-admin-code.git',
            'https://github.com/peanut-business/peanut-admin-code.git',
            'https://github.com/peanut-business/peanut-admin-code',
            'ssh://git@github.com/peanut-business/peanut-admin-code.git'], true)
            || trim($git(['rev-parse', '--is-shallow-repository'])) !== 'false'
            || trim($git(['replace', '-l'])) !== '') {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_REPOSITORY_INVALID');
        }
        $grafts = trim($git(['rev-parse', '--path-format=absolute', '--git-path', 'info/grafts']));
        if (file_exists($grafts) || is_link($grafts)) { throw new RuntimeException('SCAFFOLD_DEVELOPMENT_GRAFTS_FORBIDDEN'); }
        $metadata = json_decode((string) file_get_contents($selection['template_package_path']), true, 512, JSON_THROW_ON_ERROR);
        $packageProfile = $metadata['profile'] ?? null;
        if (!in_array($packageProfile, ['minimal', 'standard', 'full'], true)
            || ($metadata['workflow'] ?? null) !== $workflow
            || ($to->release()['workflow'] ?? null) !== $workflow) {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PROFILE_INVALID');
        }
        $target = $metadata['source'] ?? [];
        $identities = [];
        $excluded = [];
        foreach (['base' => $base, 'target' => $target] as $side => $source) {
            foreach (['commit', 'tree'] as $key) {
                if (preg_match('/^[a-f0-9]{40}$/D', (string) ($source[$key] ?? '')) !== 1) {
                    throw new RuntimeException('SCAFFOLD_DEVELOPMENT_IDENTITY_INVALID');
                }
            }
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($source['inventory_sha256'] ?? '')) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($source['edition_profile_sha256'] ?? '')) !== 1
                || trim($git(['rev-parse', '--verify', $source['commit'] . '^{commit}'])) !== $source['commit']
                || trim($git(['rev-parse', '--verify', $source['commit'] . '^{tree}'])) !== $source['tree']) {
                throw new RuntimeException('SCAFFOLD_DEVELOPMENT_IDENTITY_INVALID');
            }
            $inventoryBytes = $git(['show', $source['commit'] . ':scaffold/application-template-inventory.json']);
            $editionBytes = $git(['show', $source['commit'] . ':scaffold/edition-profiles.json']);
            if (hash('sha256', $inventoryBytes) !== $source['inventory_sha256']
                || hash('sha256', $editionBytes) !== $source['edition_profile_sha256']) {
                throw new RuntimeException('SCAFFOLD_DEVELOPMENT_SOURCE_DIGEST_CHANGED');
            }
            $inventory = json_decode($inventoryBytes, true, 512, JSON_THROW_ON_ERROR);
            if (($inventory['protocol'] ?? null) !== 'peanut.create-app-inventory.v2'
                || ($inventory['template_version'] ?? null) !== ($side === 'base' ? $fromVersion : $to->version())) {
                throw new RuntimeException('SCAFFOLD_DEVELOPMENT_INVENTORY_INVALID');
            }
            if ($side === 'base' && $workflowPaths !== null) {
                $expectedPaths = [];
                $available = 0;
                foreach ($inventory['files'] ?? [] as $row) {
                    if (($row['classification'] ?? null) === 'excluded'
                        || !in_array($profile, $row['profiles'] ?? [], true)) { continue; }
                    $included = $this->workflowPathSelected((string) ($row['target'] ?? ''), $workflow);
                    if ($included !== null) { $available++; }
                    if ($included === true) { $expectedPaths[] = $row['target']; }
                }
                sort($expectedPaths, SORT_STRING);
                if ($available === 0 || $workflowPaths !== $expectedPaths) {
                    throw new RuntimeException('SCAFFOLD_DEVELOPMENT_WORKFLOW_BASELINE_MISMATCH');
                }
            }
            if ($side === 'target') {
                if (($target['repository'] ?? null) !== $repository || ($target['channel'] ?? null) !== 'development'
                    || !array_key_exists('release_version', $target) || $target['release_version'] !== null
                    || $target['commit'] !== $selection['commit'] || $target['tree'] !== $selection['tree']
                    || $target['inventory_sha256'] !== $to->release()['inventory_sha256']
                    || $target['edition_profile_sha256'] !== ($to->data['edition']['source_sha256'] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_DEVELOPMENT_TARGET_INVALID');
                }
                foreach ($inventory['files'] ?? [] as $row) {
                    if (!is_array($row['profiles'] ?? null) || !is_string($row['target'] ?? null)
                        || array_diff($row['profiles'], ['minimal', 'standard', 'full']) !== []) {
                        throw new RuntimeException('SCAFFOLD_DEVELOPMENT_INVENTORY_INVALID');
                    }
                    // Same registered selection as ApplicationCreator, never a profile rank guess.
                    if (in_array($profile, $row['profiles'], true) && !in_array($packageProfile, $row['profiles'], true)
                        && ($row['classification'] ?? null) !== 'excluded') {
                        throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PROFILE_NOT_CONTAINED');
                    }
                    if (!in_array($profile, $row['profiles'], true)) { $excluded[] = $row['target']; }
                }
            }
            $identities[$side] = array_intersect_key($source, array_flip(['commit', 'tree', 'inventory_sha256']));
        }
        if ($base['commit'] === $target['commit']) { throw new RuntimeException('SCAFFOLD_DEVELOPMENT_NOT_SUCCESSOR'); }
        $git(['merge-base', '--is-ancestor', $base['commit'], $target['commit']], true);
        sort($excluded, SORT_STRING);
        return ['relation' => ['kind' => 'development_successor', 'repository' => $repository,
            'base' => $identities['base'], 'target' => $identities['target']], 'excluded_paths' => $excluded];
    }

    /** Reload the same authenticated archive with the plan-bound registered application selection. */
    private function planTarget(string $root, array $plan): ScaffoldManifest
    {
        $to = ScaffoldManifest::load($plan['manifest_paths']['to']);
        if (!isset($plan['identity']['development_source'])) { return $to; }
        $selection = $this->validateUpgradeSourceSelection($root, $plan['identity']['source_selection'], $to);
        $context = $plan['identity']['development_source'];
        [$application] = $this->applicationManifest($root);
        $base = $plan['identity']['from']['generation_source'] ?? [];
        if (($application['application']['profile'] ?? null) !== ($context['profile'] ?? null)
            || !is_bool($context['workflow_present'] ?? null)
            || array_key_exists('workflow', $application['application']) !== $context['workflow_present']
            || ($context['workflow_present'] && $application['application']['workflow'] !== ($context['workflow'] ?? null))
            || $this->releaseIdentity($to) !== $plan['identity']['to']) {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PLAN_BINDING_CHANGED');
        }
        if (ScaffoldSourceIdentity::from($base, true) !== ScaffoldSourceIdentity::from($plan['identity']['from'])) {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PLAN_BINDING_CHANGED');
        }
        foreach (['inventory_sha256' => 'inventory_sha256'] as $source => $release) {
            if (($base[$source] ?? null) !== ($plan['identity']['from'][$release] ?? null)) {
                throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PLAN_BINDING_CHANGED');
            }
        }
        $workflowPaths = null;
        if (!$context['workflow_present']) {
            $from = ScaffoldManifest::load($plan['manifest_paths']['from'], true);
            if (!$from->isRecordedBaseline() || $from->digest() !== $plan['identity']['from']['manifest_sha256']
                || 'sha256:' . $from->data['recorded_from']['application_manifest_sha256'] !== $plan['identity']['application_manifest_sha256']) {
                throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PLAN_BINDING_CHANGED');
            }
            $workflowPaths = $this->recordedWorkflowPaths($from);
        }
        if (($context['workflow_paths_sha256'] ?? null) !== ($workflowPaths === null ? null : hash('sha256', self::canonicalJson($workflowPaths)))) {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PLAN_BINDING_CHANGED');
        }
        $proof = $this->developmentSource($plan['identity']['from']['generation_source'], $to, $selection,
            $context['profile'], $context['workflow'], $plan['identity']['from']['version'], $workflowPaths);
        if (self::canonicalJson($proof['relation']) !== self::canonicalJson($plan['identity']['source_relation'] ?? null)
            || $context['excluded_paths_sha256'] !== hash('sha256', self::canonicalJson($proof['excluded_paths']))) {
            throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PLAN_BINDING_CHANGED');
        }
        return $to->withoutPaths($proof['excluded_paths']);
    }

    private function targetGenerationSource(array $application, ScaffoldManifest $to, ?array $selection): array
    {
        $old = is_array($application['generation_source'] ?? null) ? $application['generation_source'] : [];
        return [
            'repository' => $selection['repository'] ?? $old['repository'] ?? null,
            'requested_ref' => $selection['requested_ref'] ?? ($to->sourceIdentity()['kind'] === 'local'
                ? 'local:' . $to->sourceIdentity()['snapshot_sha256'] : $to->sourceIdentity()['commit']),
            'channel' => $selection['channel'] ?? 'development',
            'release_version' => $selection === null || $selection['channel'] === 'development' ? null : $to->version(),
            ...ScaffoldSourceIdentity::fields($to->sourceIdentity(), true),
            'inventory_sha256' => $to->release()['inventory_sha256'],
            'edition_profile_sha256' => $to->data['edition']['source_sha256']
                ?? $old['edition_profile_sha256'] ?? null,
        ];
    }

    private function assertReleaseChain(array $application, ScaffoldManifest $from, ScaffoldManifest $to, bool $allowSameVersion = false): void
    {
        if ((!$allowSameVersion && Semver::greaterThanOrEqualTo($from->version(), $to->version())) || ($application['template']['version'] ?? null) !== $from->version()
            || ScaffoldSourceIdentity::from($application['template'] ?? []) !== $from->sourceIdentity()) {
            throw new RuntimeException('SCAFFOLD_RELEASE_CHAIN_INVALID');
        }
        $applicationEdition = $application['edition'] ?? null;
        $fromEdition = $from->data['edition'] ?? null;
        $toEdition = $to->data['edition'] ?? null;
        $name = $application['application']['edition'] ?? null;
        if ($name === null && $applicationEdition === null && $fromEdition === null && $toEdition === null) {
            // Immutable pre-Edition releases have no Edition identity. Their
            // generic upgrade chain remains valid, but it cannot convert an
            // instance into either current Edition implicitly.
            return;
        }
        $expectedBootstrap = [
            'kind' => 'real-default-tenant',
            'code' => 'default',
            'tenant_identity' => 'required',
            'rbac' => 'required',
            'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
            'module_lifecycle' => 'required',
        ];
        if (!in_array($name, ['standalone', 'multi-tenant'], true)
            || !is_array($applicationEdition) || ($applicationEdition['name'] ?? null) !== $name
            || !is_array($fromEdition) || ($fromEdition['name'] ?? null) !== $name
            || !is_array($toEdition) || ($toEdition['name'] ?? null) !== $name
            || ($toEdition['deployment_mode'] ?? null) !== $name
            || ($toEdition['module_profile'] ?? null) !== 'official-default'
            || ($toEdition['tenant_bootstrap'] ?? null) !== $expectedBootstrap
            || (isset($applicationEdition['tenant_bootstrap'])
                && $applicationEdition['tenant_bootstrap'] !== $expectedBootstrap)
            || (isset($fromEdition['tenant_bootstrap'])
                && $fromEdition['tenant_bootstrap'] !== $expectedBootstrap)) {
            throw new RuntimeException('SCAFFOLD_EDITION_CHAIN_INVALID');
        }
    }

    /** @param array<string,mixed> $prepared */
    private function buildAdoptionPlan(
        string $root,
        array $prepared,
        string $packageRoot,
    ): array {
        $adoption = $prepared['adoption'] ?? null;
        if (!is_array($adoption) || !is_array($adoption['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_NOT_AVAILABLE');
        }
        [$application, $applicationDigest] = $this->applicationManifest($root);
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        $to = ScaffoldManifest::load($prepared['to_manifest']);
        $parameters = $this->parameters($application, $this->instanceVersion($versionContract));
        $applicationFiles = [];
        foreach ($application['files'] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $applicationFiles[$file['path']] = $file;
            }
        }
        $targetFiles = $to->files();
        $actions = [];
        foreach (EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS as $path) {
            $entry = $adoption['files'][$path] ?? null;
            $target = $targetFiles[$path] ?? null;
            $instance = $applicationFiles[$path] ?? null;
            if (!is_array($entry) || !is_array($target)
                || ($instance !== null && ($instance['classification'] ?? null) !== 'app-owned')) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_NOT_REQUIRED: ' . $path);
            }
            $current = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
            if (!$current['present']) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_FILE_MISSING: ' . $path);
            }
            $oldSource = (string) $entry['absolute_source'];
            $oldBytes = file_get_contents($oldSource);
            if (!is_string($oldBytes) || !hash_equals((string) $entry['sha256'], hash('sha256', $oldBytes))) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_SOURCE_DRIFT: ' . $path);
            }
            $targetBytes = $this->renderCurrentVersionArtifact($to, $target, $parameters, $versionContract);
            $actions[] = [
                'path' => $path,
                'old' => ['sha256' => hash('sha256', $oldBytes), 'mode' => $entry['mode'], 'source' => $oldSource],
                'current' => $current,
                'target' => ['sha256' => hash('sha256', $targetBytes), 'mode' => $target['mode']],
                'baseline_path' => '.peanut/scaffold-baseline/' . $adoption['source']['version'] . '/files/' . $path,
                'metadata_action' => $instance === null ? 'add-managed-record' : 'adopt-app-owned-record',
            ];
        }
        usort($actions, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $paths = array_column($actions, 'path');
        $managedState = $this->actionState($root, $actions);
        $appOwnedState = $this->ownershipState($root, $application, 'app-owned');
        $identity = [
            'from' => $adoption['source'],
            'to' => $this->releaseIdentity($to),
            'edition' => $to->data['edition'],
            'source_edition' => $application['edition'],
            'application_manifest_sha256' => $applicationDigest,
            'version_contract_sha256' => $versionContractDigest,
            'managed_pre_sha256' => $managedState['digest'],
            'app_owned_pre_sha256' => $appOwnedState['digest'],
            'package_inventory_sha256' => $prepared['package']['inventory_sha256'],
            'package_manifest_sha256' => $prepared['package']['manifest_sha256'],
        ];
        $candidateDigest = hash('sha256', self::canonicalJson([$identity, $actions]));
        $metadataWrites = ['.peanut/application-manifest.json'];
        foreach ($actions as $action) {
            $metadataWrites[] = $action['baseline_path'];
        }
        sort($metadataWrites, SORT_STRING);
        return [
            'schema_version' => 1,
            'protocol' => 'peanut.scaffold-ownership-adoption-plan.v1',
            'candidate' => 'ownership-adoption-' . substr($candidateDigest, 0, 24),
            'status' => 'ready',
            'plan_sha256' => 'sha256:' . $candidateDigest,
            'paths' => $paths,
            'paths_sha256' => 'sha256:' . hash('sha256', self::canonicalJson($paths)),
            'identity' => $identity,
            'formal_package' => [
                'root' => realpath($packageRoot),
            ],
            'metadata_writes' => $metadataWrites,
            'actions' => $actions,
        ];
    }

    private function nextAdoptedApplicationManifest(string $root, array $plan): array
    {
        [$application] = $this->applicationManifest($root);
        $adopted = array_fill_keys($plan['paths'], true);
        $files = [];
        foreach ($application['files'] as $file) {
            if (!isset($adopted[$file['path']])) {
                $files[] = $file;
            }
        }
        foreach ($plan['actions'] as $action) {
            $files[] = [
                'path' => $action['path'],
                'sha256' => $action['current']['sha256'],
                'mode' => $action['old']['mode'],
                'classification' => 'managed',
                'owner' => 'scaffold',
                'source' => $action['path'],
                'baseline_path' => $action['baseline_path'],
                'baseline_sha256' => $action['old']['sha256'],
                'baseline_mode' => $action['old']['mode'],
            ];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $managed = array_values(array_filter($files, static fn(array $f): bool => in_array($f['classification'], ['managed', 'generated-managed'], true)));
        $appOwned = array_values(array_filter($files, static fn(array $f): bool => $f['classification'] === 'app-owned'));
        $application['files'] = $files;
        $application['digests']['managed_tree_sha256'] = $this->manifestTreeDigest($managed);
        $application['digests']['app_owned_tree_sha256'] = $this->manifestTreeDigest($appOwned);
        $application['last_ownership_adoption'] = [
            'candidate' => $plan['candidate'],
            'source' => $plan['identity']['from'],
            'paths_sha256' => $plan['paths_sha256'],
        ];
        return $application;
    }

    private function adoptionFault(int $writes): void
    {
        if ($this->failAfterAdoptionWrites !== null && $writes >= $this->failAfterAdoptionWrites) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_FAULT_INJECTED');
        }
    }

    /**
     * Compare the recorded adoption rendering with the live tree and render the target
     * from the current application release frozen by preflight.
     */
    private function classify(
        string $root,
        array $application,
        ScaffoldManifest $from,
        ScaffoldManifest $to,
        array $fromParameters,
        array $targetParameters,
        array $versionContract,
    ): array {
        $old = $from->files();
        $new = $to->files();
        $actions = [];
        $applicationFiles = [];
        foreach ($application['files'] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $applicationFiles[$file['path']] = $file;
            }
        }
        $renamedFrom = [];
        $renamedTo = [];
        foreach ($to->renames() as $rename) {
            $fromPath = (string) $rename['from'];
            $toPath = (string) $rename['to'];
            $before = $old[$fromPath] ?? null;
            $after = $new[$toPath] ?? null;
            if ($before === null || $after === null || isset($old[$toPath]) || isset($new[$fromPath])
                || ($before['owner'] ?? null) !== ($rename['owner'] ?? null)
                || ($after['owner'] ?? null) !== ($rename['owner'] ?? null)) {
                throw new RuntimeException("SCAFFOLD_RENAME_CONTRACT_INVALID: {$fromPath} -> {$toPath}");
            }
            $sourceInstance = $applicationFiles[$fromPath] ?? null;
            if (!is_array($sourceInstance)
                || !in_array($sourceInstance['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                throw new RuntimeException("SCAFFOLD_RENAME_SOURCE_OWNERSHIP_INVALID: {$fromPath}");
            }
            if (isset($applicationFiles[$toPath])) {
                throw new RuntimeException("SCAFFOLD_RENAME_TARGET_OWNERSHIP_CONFLICT: {$toPath}");
            }
            $sourceCurrent = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $fromPath), $fromPath);
            $targetCurrent = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $toPath), $toPath);
            $oldContent = $this->renderArtifact($from, $before, $fromParameters);
            $currentVersionContent = $this->renderCurrentVersionArtifact($from, $before, $targetParameters, $versionContract);
            $targetContent = $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract);
            $targetDigest = hash('sha256', $targetContent);
            $sourceChanged = !$sourceCurrent['present']
                || (!$this->renderedContentMatches($root, $fromPath, $sourceCurrent, $oldContent)
                    && !$this->renderedContentMatches($root, $fromPath, $sourceCurrent, $currentVersionContent))
                || ($sourceCurrent['mode'] ?? null) !== ($before['mode'] ?? null);
            $action = 'rename';
            $reason = 'upstream_renamed';
            $conflict = false;
            $resolutionOptions = null;
            $preserveKind = null;
            if (!$sourceCurrent['present']) {
                $action = 'conflict';
                $reason = 'rename_source_missing';
                $conflict = true;
                $resolutionOptions = ['replace'];
            } elseif ($sourceChanged && $targetCurrent['present']) {
                $action = 'conflict';
                $reason = 'rename_source_and_target_modified';
                $conflict = true;
                $resolutionOptions = ['replace'];
            } elseif ($sourceChanged) {
                $action = 'conflict';
                $reason = 'project_modified_upstream_renamed';
                $conflict = true;
                $resolutionOptions = ['preserve', 'replace'];
                $preserveKind = 'source';
            } elseif ($targetCurrent['present']) {
                $action = 'conflict';
                $reason = 'rename_target_already_exists';
                $conflict = true;
                $resolutionOptions = ['preserve', 'replace'];
                $preserveKind = 'target';
            }
            $renameAction = $this->action($toPath, $after, $action, $reason, $conflict, $targetCurrent, $targetDigest);
            $renameAction['rename_from'] = $fromPath;
            $renameAction['rename_from_current'] = $sourceCurrent;
            if ($resolutionOptions !== null) {
                $renameAction['resolution_options'] = $resolutionOptions;
            }
            if ($preserveKind !== null) {
                $renameAction['rename_preserve'] = $preserveKind;
            }
            $actions[] = $renameAction;
            $renamedFrom[$fromPath] = true;
            $renamedTo[$toPath] = true;
        }
        $paths = array_unique(array_merge(array_keys($old), array_keys($new)));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (isset($renamedFrom[$path]) || isset($renamedTo[$path])) {
                continue;
            }
            $before = $old[$path] ?? null;
            $after = $new[$path] ?? null;
            $projectPath = ScaffoldPathGuard::projectPath($root, $path);
            $current = $this->regularFileState($projectPath, $path);
            $instanceFile = $applicationFiles[$path] ?? null;
            if (($instanceFile['classification'] ?? null) === 'app-owned') {
                $file = $after ?? $before ?? [];
                $target = $after === null ? null : hash('sha256', $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract));
                $actions[] = $this->action($path, $file, 'conflict', 'app_owned_adoption_required', true, $current, $target);
                continue;
            }
            if ($before !== null && (!$instanceFile
                || !in_array($instanceFile['classification'] ?? null, ['managed', 'generated-managed'], true))) {
                $file = $after ?? $before;
                $target = $after === null ? null : hash('sha256', $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract));
                $actions[] = $this->action($path, $file, 'conflict', 'managed_adoption_required', true, $current, $target);
                continue;
            }
            if ($after === null) {
                // Platform files from older baselines need an explicit ownership
                // transition; removing them from the neutral template is not deletion consent.
                if (str_starts_with($path, '.github/')) {
                    $actions[] = $this->action($path, $before ?? [], 'conflict', 'recipe_ownership_transition_required', true, $current, null);
                    continue;
                }
                if (!$current['present']) {
                    $actions[] = $this->action($path, $before ?? [], 'conflict', 'managed_file_missing', true, $current, null);
                    continue;
                }
                $oldContent = $this->renderArtifact($from, $before, $fromParameters);
                $currentVersionContent = $this->renderCurrentVersionArtifact($from, $before, $targetParameters, $versionContract);
                $actions[] = (($this->renderedContentMatches($root, $path, $current, $oldContent)
                        || $this->renderedContentMatches($root, $path, $current, $currentVersionContent))
                    && ($current['mode'] ?? null) === ($before['mode'] ?? null))
                    ? $this->action($path, $before, 'delete', 'upstream_removed_only', false, $current, null)
                    : $this->action($path, $before, 'conflict', 'project_modified_upstream_removed', true, $current, null);
                continue;
            }
            $targetContent = $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract);
            $targetDigest = hash('sha256', $targetContent);
            if ($before === null) {
                $decision = ScaffoldManifest::additionDecision(
                    $current['present'],
                    $this->renderedContentMatches($root, $path, $current, $targetContent)
                        && ($current['mode'] ?? null) === ($after['mode'] ?? null),
                );
                $actions[] = $this->action($path, $after, $decision['action'], $decision['reason'], $decision['conflict'], $current, $targetDigest);
                continue;
            }
            $oldContent = $this->renderArtifact($from, $before, $fromParameters);
            $currentVersionContent = $this->renderCurrentVersionArtifact($from, $before, $targetParameters, $versionContract);
            $currentVersionDigest = hash('sha256', $currentVersionContent);
            if (!$current['present']) {
                $actions[] = $this->action($path, $after, 'conflict', 'managed_file_missing', true, $current, $targetDigest);
                continue;
            }
            if ($this->renderedContentMatches($root, $path, $current, $targetContent)
                && ($current['mode'] ?? null) === ($after['mode'] ?? null)) {
                $actions[] = $this->action($path, $after, 'preserve', 'already_at_target', false, $current, $targetDigest);
                continue;
            }
            $projectChanged = (!$this->renderedContentMatches($root, $path, $current, $oldContent)
                    && !$this->renderedContentMatches($root, $path, $current, $currentVersionContent))
                || ($current['mode'] ?? null) !== ($before['mode'] ?? null);
            $upstreamChanged = !hash_equals($currentVersionDigest, $targetDigest)
                || ($before['mode'] ?? null) !== ($after['mode'] ?? null);
            if ($projectChanged && $upstreamChanged) {
                $actions[] = $this->action($path, $after, 'conflict', 'both_project_and_upstream_modified', true, $current, $targetDigest);
            } elseif ($projectChanged) {
                $actions[] = $this->action($path, $after, 'preserve', 'project_modified_only', false, $current, $targetDigest);
            } else {
                $actions[] = $this->action($path, $after, $after['classification'] === 'generated-managed' ? 'regenerate' : 'replace', $upstreamChanged ? 'upstream_modified_only' : 'application_version_projection', false, $current, $targetDigest);
            }
        }
        return $actions;
    }

    private function action(string $path, array $file, string $action, string $reason, bool $conflict, ?array $current, ?string $target): array
    {
        return ['path' => $path, 'classification' => $file['classification'] ?? 'managed', 'owner' => $file['owner'] ?? 'host',
            'policy' => $file['policy'] ?? 'managed', 'transform' => $file['transform'] ?? 'copy', 'mode' => $file['mode'] ?? 0644,
            'source' => $file['source'] ?? null, 'template_sha256' => $file['template_sha256'] ?? null, 'action' => $action,
            'reason' => $reason, 'conflict' => $conflict, 'current' => $current, 'target_sha256' => $target];
    }

    private function renderArtifact(ScaffoldManifest $manifest, array $file, array $parameters): string
    {
        $path = $manifest->artifactPath($file);
        $raw = file_get_contents($path);
        if (!is_string($raw) || !hash_equals($file['template_sha256'], hash('sha256', $raw))) {
            throw new RuntimeException('SCAFFOLD_ARTIFACT_DIGEST_MISMATCH: ' . $file['path']);
        }
        if ($manifest->isRecordedBaseline()) { return $raw; }
        $tokens = $manifest->release()['tokens'];
        $expectedKeys = $manifest->supportsApplicationVersion()
            ? ['product_name','slug','package_identity','application_version']
            : ['product_name','slug','package_identity'];
        if (array_keys($tokens) !== $expectedKeys) {
            throw new RuntimeException('SCAFFOLD_RELEASE_TOKENS_INVALID');
        }
        $values = [
            'product_name' => $parameters['PRODUCT_NAME'],
            'slug' => $parameters['SLUG'],
            'package_identity' => $parameters['PACKAGE_IDENTITY'],
            'application_version' => $parameters['APPLICATION_VERSION'],
        ];
        $rendered = $raw;
        foreach ($tokens as $key => $token) {
            $rendered = str_replace($token, $values[$key], $rendered);
        }
        if (($file['transform'] ?? null) === 'composer-lock') {
            $composer = $manifest->files()['server/composer.json'] ?? null;
            if (!is_array($composer)) {
                throw new RuntimeException('SCAFFOLD_COMPOSER_COMPANION_MISSING');
            }
            $composerPath = $manifest->artifactPath($composer);
            $composerRaw = file_get_contents($composerPath);
            if (!is_string($composerRaw)
                || !hash_equals((string) ($composer['template_sha256'] ?? ''), hash('sha256', $composerRaw))) {
                throw new RuntimeException('SCAFFOLD_COMPOSER_COMPANION_INVALID');
            }
            foreach ($tokens as $key => $token) {
                $composerRaw = str_replace($token, $values[$key], $composerRaw);
            }
            return ScaffoldManifest::renderComposerLock($rendered, $composerRaw);
        }
        return $rendered;
    }

    /**
     * Keep the application's current release and original generated default while
     * accepting the target scaffold/Core fields from the immutable target artifact.
     */
    private function renderCurrentVersionArtifact(
        ScaffoldManifest $manifest,
        array $file,
        array $parameters,
        array $versionContract,
    ): string {
        $rendered = $this->renderArtifact($manifest, $file, $parameters);
        if (($file['path'] ?? null) !== 'release-versions.json') {
            return $rendered;
        }
        $document = $this->normalizeVersionContractDocument(
            $rendered,
            'SCAFFOLD_VERSION_CONTRACT_TARGET_INVALID',
        );
        if ($document['schema_version'] >= 2) {
            $document['instance_version'] = $this->instanceVersion($versionContract);
        } else {
            $instanceVersion = $this->instanceVersion($versionContract);
            $document['product_release'] = $instanceVersion;
        }
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** Render apply bytes only from the immutable parameters and version contract in the plan. */
    private function targetContent(ScaffoldManifest $manifest, array $action, array $plan): string
    {
        $derived = $plan['identity']['plugin_target']['manifest_contents'][$action['path']] ?? null;
        if (is_string($derived)) {
            return $derived;
        }
        if ($action['path'] === 'plugins.lock' && is_array($plan['identity']['plugin_target'] ?? null)) {
            return $plan['identity']['plugin_target']['lock_contents'];
        }
        return $this->renderCurrentVersionArtifact(
            $manifest,
            $action,
            $plan['identity']['target_parameters'],
            $plan['identity']['version_contract'],
        );
    }

    /** Use the validated live Plugin bytes as the next baseline without rewriting the installed Plugin. */
    private function baselineContent(string $root, ScaffoldManifest $manifest, array $action, array $plan): string
    {
        if (($action['baseline_source'] ?? null) !== 'validated-plugin-projection') {
            return $this->targetContent($manifest, $action, $plan);
        }
        $path = ScaffoldPathGuard::projectPath($root, $action['path']);
        $state = $this->regularFileState($path, $action['path']);
        $content = $state['present'] ? file_get_contents($path) : false;
        if (!is_string($content)
            || $state !== $action['current']
            || !hash_equals((string) $action['target_sha256'], hash('sha256', $content))) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED: ' . $action['path']);
        }
        return $content;
    }

    /**
     * Resolve the installed Plugin graph with the production verifier and freeze only
     * its normalized identities. Root bytes are covered by each canonical source digest.
     *
     * @return array<string,mixed>
     */
    private function pluginProjection(string $root, bool $sourceApplication): array
    {
        $lockPath = ScaffoldPathGuard::projectPath($root, 'plugins.lock');
        $lockState = $this->regularFileState($lockPath, 'plugins.lock');
        if (!$lockState['present']) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_LOCK_MISSING');
        }
        try {
            $resolved = $sourceApplication
                ? PluginLockResolver::forSourceUpgrade($root)
                : (new PluginLockResolver(
                    ScaffoldPathGuard::projectPath($root, 'server'),
                    '../plugins.lock',
                ))->all();
            $lockBytes = file_get_contents($lockPath);
            $lockEndState = $this->regularFileState($lockPath, 'plugins.lock');
            if (!is_string($lockBytes)
                || $lockEndState !== $lockState
                || !hash_equals((string) $lockState['sha256'], hash('sha256', $lockBytes))) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED');
            }
            $lock = json_decode($lockBytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (PluginLifecycleException $exception) {
            throw new RuntimeException(
                'SCAFFOLD_PLUGIN_PROJECTION_INVALID: ' . $exception->errorCode . ': ' . $exception->getMessage(),
                0,
                $exception,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID', 0, $exception);
        }
        if (!is_array($lock) || !is_array($lock['plugins'] ?? null) || !array_is_list($lock['plugins'])) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
        }

        $plugins = [];
        $artifactPaths = ['plugins.lock'];
        $rootPaths = [];
        foreach ($lock['plugins'] as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            $manifest = is_array($entry) ? ($entry['manifest'] ?? null) : null;
            if (!is_string($key) || !isset($resolved[$key]) || !is_string($manifest)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
            }
            $manifest = ScaffoldManifest::path($manifest);
            $manifestState = $this->regularFileState(
                ScaffoldPathGuard::projectPath($root, $manifest),
                $manifest,
            );
            /** @var PluginDescriptor $descriptor */
            $descriptor = $resolved[$key];
            if (!$manifestState['present']
                || !hash_equals($descriptor->manifestDigest, (string) $manifestState['sha256'])) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_MANIFEST_INVALID');
            }
            $moduleRoots = [];
            $frontendRoots = [];
            foreach ($descriptor->moduleRoots as $moduleKey => $absoluteRoot) {
                $moduleRoots[] = $this->relativeDirectory($root, $absoluteRoot);
            }
            foreach ($descriptor->frontend as $frontend) {
                $entry = is_array($frontend) ? ($frontend['entry'] ?? null) : null;
                if (!is_string($entry)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_MANIFEST_INVALID');
                }
                $frontendRoot = dirname(ScaffoldManifest::path($entry));
                $frontendPath = ScaffoldPathGuard::projectPath($root, $frontendRoot);
                if (!is_dir($frontendPath)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_PATH_UNAVAILABLE');
                }
                $frontendRoots[] = $frontendRoot;
            }
            sort($moduleRoots, SORT_STRING);
            sort($frontendRoots, SORT_STRING);
            $plugins[$key] = [
                'version' => $descriptor->version,
                'manifest' => $manifest,
                'manifest_sha256' => $descriptor->manifestDigest,
                'source_sha256' => $descriptor->source['sha256'],
                'source_contract' => ['type' => $descriptor->source['type'], 'channel' => $descriptor->trust['channel'], 'origin' => $descriptor->trust['origin']],
                'module_roots' => $moduleRoots,
                'module_keys' => array_keys($descriptor->moduleRoots),
                'frontend_roots' => $frontendRoots,
                'manifest_state' => $manifestState,
            ];
            $artifactPaths[] = $manifest;
            array_push($rootPaths, ...$moduleRoots, ...$frontendRoots);
        }
        if (array_diff(array_keys($resolved), array_keys($plugins)) !== []) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
        }
        ksort($plugins, SORT_STRING);
        $artifactPaths = array_values(array_unique($artifactPaths));
        $rootPaths = array_values(array_unique($rootPaths));
        sort($artifactPaths, SORT_STRING);
        sort($rootPaths, SORT_STRING);
        $projection = [
            'schema_version' => 1,
            'lock_state' => $lockState,
            'plugins' => $plugins,
            'artifact_paths' => $artifactPaths,
            'root_paths' => $rootPaths,
        ];
        $projection['sha256'] = 'sha256:' . hash('sha256', self::canonicalJson($projection));
        return $projection;
    }

    /** @return array<string,array{manifest:string,module_roots:list<string>,frontend_roots:list<string>}> */
    private function targetPluginIndex(
        ScaffoldManifest $manifest,
        array $parameters,
        array $versionContract,
    ): array {
        $files = $manifest->files();
        $lockFile = $files['plugins.lock'] ?? null;
        if (!is_array($lockFile)) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_MISSING');
        }
        try {
            $lock = json_decode(
                $this->renderCurrentVersionArtifact($manifest, $lockFile, $parameters, $versionContract),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID', 0, $exception);
        }
        if (!is_array($lock)
            || ($lock['schema_version'] ?? null) !== 1
            || !is_array($lock['plugins'] ?? null)
            || !array_is_list($lock['plugins'])) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
        }
        $plugins = [];
        foreach ($lock['plugins'] as $entry) {
            if (!is_array($entry)
                || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', (string) ($entry['key'] ?? '')) !== 1
                || !is_string($entry['manifest'] ?? null)
                || !is_array($entry['frontend'] ?? null)
                || !array_is_list($entry['frontend'])
                || !is_array($entry['modules'] ?? null)
                || $entry['modules'] === []
                || !array_is_list($entry['modules'])) {
                throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
            }
            $key = (string) $entry['key'];
            $manifestPath = ScaffoldManifest::path($entry['manifest']);
            if (isset($plugins[$key]) || !isset($files[$manifestPath])) {
                throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
            }
            $moduleRoots = [];
            $moduleKeys = [];
            $frontendRoots = [];
            foreach ($entry['modules'] as $module) {
                if (!is_array($module)
                    || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', (string) ($module['key'] ?? '')) !== 1
                    || !is_string($module['root'] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $moduleRoot = ScaffoldManifest::path($module['root']);
                if (isset($moduleRoots[$moduleRoot])) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $moduleRoots[$moduleRoot] = true;
                $moduleKeys[] = $module['key'];
            }
            foreach ($entry['frontend'] ?? [] as $frontend) {
                if (!is_array($frontend) || !is_string($frontend['entry'] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $frontendRoots[dirname(ScaffoldManifest::path($frontend['entry']))] = true;
            }
            $plugins[$key] = [
                'manifest' => $manifestPath,
                'version' => $entry['version'] ?? null,
                'source_sha256' => $entry['source']['sha256'] ?? null,
                'source_contract' => ['type' => $entry['source']['type'] ?? null, 'channel' => $entry['trust']['channel'] ?? null, 'origin' => $entry['trust']['origin'] ?? null],
                'manifest_sha256' => $entry['manifest_sha256'] ?? null,
                'module_keys' => $moduleKeys,
                'module_roots' => array_keys($moduleRoots),
                'frontend_roots' => array_keys($frontendRoots),
            ];
            sort($plugins[$key]['module_roots'], SORT_STRING);
            sort($plugins[$key]['frontend_roots'], SORT_STRING);
        }
        ksort($plugins, SORT_STRING);
        return $plugins;
    }

    /** A namespace or bundled label alone never grants upstream ownership of a private/customer package. */
    private function managedBundledUpstreamProofs(
        string $root,
        array $application,
        array $current,
        array $from,
        array $fromFiles,
        array $target,
        array $targetFiles,
    ): array {
        $baselineRoot = '.peanut/scaffold-baseline/' . $application['template']['version'] . '/files';
        if (($application['ownership']['baseline_root'] ?? null) !== $baselineRoot) {
            return [];
        }
        $applicationFiles = array_column($application['files'], null, 'path');
        $contract = ['type' => 'canonical-contents', 'channel' => 'bundled',
            'origin' => ['type' => 'repository-contents', 'reference' => 'canonical-plugin-contents-v1']];
        $proofs = [];
        foreach ($target as $key => $plugin) {
            if (str_starts_with($key, 'official.') || !isset($current['plugins'][$key], $from[$key])
                || $plugin['manifest'] !== 'plugins/' . $key . '/plugin.json') {
                continue;
            }
            $installed = $current['plugins'][$key];
            $baseline = $from[$key];
            $sameOwner = $installed['frontend_roots'] === $baseline['frontend_roots']
                && array_diff($baseline['frontend_roots'], $plugin['frontend_roots']) === [];
            foreach (['manifest', 'module_keys', 'module_roots'] as $field) {
                $sameOwner = $sameOwner && $installed[$field] === $plugin[$field] && $baseline[$field] === $plugin[$field];
            }
            // Only an unoccupied target frontend addition may extend proven upstream membership.
            // Namespaces and bundled labels never transfer existing application ownership.
            $additions = [];
            foreach (array_diff($plugin['frontend_roots'], $baseline['frontend_roots']) as $addition) {
                $path = ScaffoldPathGuard::projectPath($root, $addition);
                if (file_exists($path) || is_link($path)) { $sameOwner = false; }
                foreach (array_keys($applicationFiles) as $ownedPath) {
                    if ($ownedPath === $addition || $this->withinAnyRoot($ownedPath, [$addition])
                        || $this->withinAnyRoot($addition, [$ownedPath])) { $sameOwner = false; }
                }
                foreach ($current['plugins'] as $existing) {
                    foreach ([...$existing['module_roots'], ...$existing['frontend_roots']] as $ownedRoot) {
                        if ($ownedRoot === $addition || $this->withinAnyRoot($addition, [$ownedRoot])
                            || $this->withinAnyRoot($ownedRoot, [$addition])) { $sameOwner = false; }
                    }
                }
                $members = [];
                foreach ($targetFiles as $targetPath => $targetFile) {
                    if (!$this->withinAnyRoot($targetPath, [$addition])) { continue; }
                    if (!in_array($targetFile['classification'] ?? null, ['managed', 'generated-managed'], true)
                        || isset($fromFiles[$targetPath])) { $sameOwner = false; }
                    $members[$targetPath] = ['template_sha256' => $targetFile['template_sha256'], 'mode' => $targetFile['mode']];
                }
                if ($members === []) { $sameOwner = false; }
                ksort($members, SORT_STRING);
                $additions[$addition] = $members;
            }
            foreach ([$installed, $baseline, $plugin] as $identity) {
                $sameOwner = $sameOwner && ($identity['source_contract'] ?? null) === $contract;
            }
            if (!$sameOwner) {
                continue;
            }
            $paths = $this->pluginSourceFiles($root, [...$installed['module_roots'], ...$installed['frontend_roots']]);
            $paths[$installed['manifest']] = $installed['manifest_sha256'];
            ksort($paths, SORT_STRING);
            $evidence = [];
            foreach ($paths as $path => $digest) {
                $file = $applicationFiles[$path] ?? null;
                $old = $fromFiles[$path] ?? null;
                $next = $targetFiles[$path] ?? null;
                if (!is_array($file) || !is_array($old)
                    || !in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)
                    || ($file['owner'] ?? null) !== 'scaffold'
                    || ($file['source'] ?? null) !== $path
                    || !in_array($old['classification'] ?? null, ['managed', 'generated-managed'], true)
                    || ($next !== null && !in_array($next['classification'] ?? null, ['managed', 'generated-managed'], true))
                    || ($file['sha256'] ?? null) !== $digest
                    || ($file['baseline_path'] ?? null) !== $baselineRoot . '/' . $path
                    || ($file['baseline_sha256'] ?? null) !== $digest) {
                    $sameOwner = false;
                    break;
                }
                $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
                $saved = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['baseline_path']), $file['baseline_path']);
                if (!$saved['present'] || $saved['sha256'] !== $digest || $actual['sha256'] !== $digest
                    || $actual['mode'] !== ($file['mode'] ?? null)) {
                    $sameOwner = false;
                    break;
                }
                $evidence[$path] = ['sha256' => $digest, 'mode' => $actual['mode'], 'baseline_path' => $file['baseline_path']];
            }
            if ($sameOwner) {
                ksort($additions, SORT_STRING);
                $proofs[$key] = ['baseline_root' => $baselineRoot, 'files' => count($evidence),
                    'frontend_roots' => $plugin['frontend_roots'],
                    'ownership_sha256' => 'sha256:' . hash('sha256', self::canonicalJson([
                        'existing' => $evidence, 'frontend_additions' => $additions,
                    ]))];
            }
        }
        return $proofs;
    }

    /** Materialize the selected upstream graph and validated customer graph through the native canonical writer. */
    private function upstreamPluginProjection(
        string $root,
        array $current,
        array $fromFiles,
        array $managedUpstreamProofs,
        array $target,
        ScaffoldManifest $release,
        array $parameters,
        array $versionContract,
    ): array {
        $keys = [];
        foreach ($target as $key => $plugin) {
            $official = str_starts_with($key, 'official.');
            if (!$official && !isset($managedUpstreamProofs[$key])) {
                continue;
            }
            if ($plugin['manifest'] !== 'plugins/' . $key . '/plugin.json'
                || ($official && array_filter($plugin['module_keys'], static fn(string $module): bool => !str_starts_with($module, 'official.')) !== [])
                || (!$official && array_filter($plugin['module_keys'], static fn(string $module): bool => str_starts_with($module, 'official.')) !== [])) {
                throw new RuntimeException('SCAFFOLD_UPSTREAM_OWNERSHIP_INVALID');
            }
            $installed = $current['plugins'][$key] ?? null;
            if (is_array($installed)) {
                foreach (['manifest', 'module_keys', 'module_roots', 'frontend_roots'] as $field) {
                    if ($installed[$field] !== $plugin[$field]
                        && !(!$official && $field === 'frontend_roots'
                            && ($managedUpstreamProofs[$key]['frontend_roots'] ?? null) === $plugin[$field])) {
                        throw new RuntimeException('SCAFFOLD_OFFICIAL_MEMBERSHIP_CHANGED: ' . $key);
                    }
                }
                $comparison = version_compare((string) $plugin['version'], (string) $installed['version']);
                if ($comparison < 0) {
                    throw new RuntimeException('SCAFFOLD_OFFICIAL_VERSION_IDENTITY_CONFLICT: ' . $key);
                }
            }
            foreach ($current['plugins'] as $owner => $existing) {
                if ($owner !== $key
                    && (array_intersect($plugin['module_keys'], $existing['module_keys']) !== []
                        || array_intersect($plugin['module_roots'], $existing['module_roots']) !== []
                        || array_intersect($plugin['frontend_roots'], $existing['frontend_roots']) !== [])) {
                    throw new RuntimeException('SCAFFOLD_OFFICIAL_OWNERSHIP_CONFLICT: ' . $key);
                }
            }
            $keys[] = $key;
        }
        if ($keys === []) {
            return ['keys' => [], 'lock_contents' => (string) file_get_contents($root . '/plugins.lock'), 'projection' => $current];
        }

        $stage = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/plugin-projection-' . bin2hex(random_bytes(16)));
        if (!mkdir($stage, 0700, true)) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_STAGE_FAILED');
        }
        try {
            $sourceProjection = $this->validateRawPluginProjection($root, $stage, $release, $target);
            // Customer sources are copied, never reinterpreted as upstream files.
            foreach ($current['plugins'] as $key => $plugin) {
                if (in_array($key, $keys, true)) {
                    continue;
                }
                foreach ([$plugin['manifest'], ...$plugin['module_roots'], ...$plugin['frontend_roots']] as $path) {
                    $this->copyPluginProjectionPath($root, $stage, $path);
                }
            }
            $files = $release->files();
            foreach ($keys as $key) {
                $plugin = $target[$key];
                $roots = [...$plugin['module_roots'], ...$plugin['frontend_roots']];
                if (isset($current['plugins'][$key])) {
                    $existing = $current['plugins'][$key];
                    foreach (array_keys($this->pluginSourceFiles($root, [...$existing['module_roots'], ...$existing['frontend_roots']])) as $path) {
                        if (!isset($files[$path]) && !isset($fromFiles[$path])) {
                            throw new RuntimeException('SCAFFOLD_OFFICIAL_UNTRACKED_SOURCE: ' . $path);
                        }
                    }
                }
                foreach ($files as $path => $file) {
                    if ($path !== $plugin['manifest'] && !$this->withinAnyRoot($path, $roots)) {
                        continue;
                    }
                    $this->writeFileAtomic(
                        ScaffoldPathGuard::projectPath($stage, $path),
                        $this->renderCurrentVersionArtifact($release, $file, $parameters, $versionContract),
                        (int) $file['mode'],
                    );
                }
            }
            $schema = 'server/resources/schemas/plugin.schema.json';
            if (isset($files[$schema])) {
                $this->writeFileAtomic(
                    ScaffoldPathGuard::projectPath($stage, $schema),
                    $this->renderCurrentVersionArtifact($release, $files[$schema], $parameters, $versionContract),
                    0644,
                );
            } else {
                $this->copyPluginProjectionPath($root, $stage, $schema);
            }
            $writer = new PluginArtifactWriter($stage . '/server');
            $manifestContents = [];
            foreach ($keys as $key) {
                $plugin = $target[$key];
                $manifest = json_decode((string) file_get_contents($stage . '/' . $plugin['manifest']), true, 128, JSON_THROW_ON_ERROR);
                $specs = [];
                foreach ($manifest['modules'] as $module) {
                    $specs[] = $module['key'] . '=' . $module['root'];
                }
                // Rendering changes source bytes; derive the APP manifest exactly as create-app does.
                $writer->make($key, $plugin['version'], $specs);
                $manifestContents[$plugin['manifest']] = (string) file_get_contents($stage . '/' . $plugin['manifest']);
                // Canonical public artifacts retain the release-declared mode regardless
                // of the caller's private-state umask, which is part of the plan identity.
                $this->writeFileAtomic(
                    $stage . '/' . $plugin['manifest'],
                    $manifestContents[$plugin['manifest']],
                    (int) $files[$plugin['manifest']]['mode'],
                );
            }
            $built = $writer->lock();
            $this->writeFileAtomic($stage . '/plugins.lock', $built['contents'], 0644);
            $projection = $this->pluginProjection($stage, sourceApplication: false);
            $preflight = new PackageCheck($stage, $this->targetModuleSchemaValidator($release, $stage));
            $modules = [];
            foreach ($projection['plugins'] as $key => $plugin) {
                foreach ($plugin['module_keys'] as $moduleKey) {
                    if (isset($modules[$moduleKey])) {
                        throw new RuntimeException('SCAFFOLD_PLUGIN_MODULE_OWNERSHIP_CONFLICT');
                    }
                    $inspection = $preflight->inspect($moduleKey);
                    $modules[$moduleKey] = ['version' => $inspection['version'], 'dependencies' => $inspection['dependencies']];
                    if (in_array($key, $keys, true) && isset($current['plugins'][$key])) {
                        $oldManifest = json_decode((string) file_get_contents($root . '/' . $inspection['backend_relative'] . '/module.json'), true, 64, JSON_THROW_ON_ERROR);
                        $oldVersion = (string) ($oldManifest['version'] ?? '');
                        $oldDigest = hash_file('sha256', $root . '/' . $inspection['backend_relative'] . '/module.json');
                        $oldFrontend = \app\platform\value\plugin\ModuleFrontendLayout::contributions($oldManifest['frontend'] ?? [], $moduleKey);
                        $oldRoots = [$inspection['backend_relative'], ...array_column($oldFrontend, 'root')];
                        $targetRoots = [$inspection['backend_relative'], ...array_column($inspection['frontend_contributions'], 'root')];
                        $changed = $this->pluginSourceFiles($root, $oldRoots) !== $this->pluginSourceFiles($stage, $targetRoots);
                        if (version_compare($inspection['version'], $oldVersion) < 0
                            || ($changed && version_compare($inspection['version'], $oldVersion) <= 0)
                            || ($inspection['version'] === $oldVersion
                                && $oldDigest !== hash_file('sha256', $stage . '/' . $inspection['backend_relative'] . '/module.json'))) {
                            throw new RuntimeException('SCAFFOLD_OFFICIAL_MODULE_VERSION_IDENTITY_CONFLICT: ' . $moduleKey);
                        }
                    }
                }
                if (in_array($key, $keys, true)) {
                    foreach (['version', 'manifest', 'module_keys', 'module_roots', 'frontend_roots'] as $field) {
                        if ($plugin[$field] !== $target[$key][$field]) {
                            throw new RuntimeException('SCAFFOLD_OFFICIAL_TARGET_IDENTITY_MISMATCH: ' . $key);
                        }
                    }
                    $installed = $current['plugins'][$key] ?? null;
                    if (is_array($installed) && version_compare($plugin['version'], $installed['version']) === 0
                        && $plugin['manifest_sha256'] !== $installed['manifest_sha256']) {
                        throw new RuntimeException('SCAFFOLD_OFFICIAL_VERSION_IDENTITY_CONFLICT: ' . $key);
                    }
                } elseif ($plugin !== ($current['plugins'][$key] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_CUSTOMER_PLUGIN_PROJECTION_CHANGED: ' . $key);
                }
            }
            $preflight->dependencyOrder($modules, []);
            $compatibility = $this->customerModuleCompatibility(
                $stage,
                $projection,
                $keys,
                $release,
                $parameters,
                $versionContract,
            );
            return ['keys' => $keys, 'source_projection' => $sourceProjection,
                'managed_upstream_proofs' => $managedUpstreamProofs,
                'manifest_contents' => $manifestContents, 'lock_contents' => $built['contents'],
                'projection' => $projection, 'compatibility' => $compatibility];
        } finally {
            $this->removePluginProjectionStage($stage);
        }
    }

    /**
     * Bootstrap target validation from authenticated data, without loading target PHP.
     * The whole projected graph (including retained customer Modules) uses this schema.
     */
    private function targetModuleSchemaValidator(ScaffoldManifest $release, string $stage): OpisManifestSchemaValidator
    {
        $files = $release->files();
        $read = static function (string $path) use ($release, $files): string {
            $file = $files[$path] ?? null;
            if (!is_array($file) || ($file['policy'] ?? null) !== 'managed') {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_INPUT_MISSING: ' . $path);
            }
            $raw = file_get_contents($release->artifactPath($file));
            if (!is_string($raw) || !hash_equals($file['template_sha256'], hash('sha256', $raw))) {
                throw new RuntimeException('SCAFFOLD_ARTIFACT_DIGEST_MISMATCH: ' . $path);
            }
            return $raw;
        };
        $versions = $this->normalizeVersionContractDocument($read('release-versions.json'), 'SCAFFOLD_TARGET_MODULE_SCHEMA_VERSION_INVALID');
        $lock = json_decode($read('server/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $cores = array_values(array_filter($lock['packages'] ?? [], static fn(array $package): bool => ($package['name'] ?? null) === 'peanut-admin/core'));
        $identity = $versions['core_php'];
        $version = is_array($identity) ? $identity['resolved_version'] : $identity;
        $core = count($cores) === 1 ? $cores[0] : null;
        $reference = $core['source']['reference'] ?? null;
        if ($versions['source_product_version'] !== $release->version()
            || !is_array($core) || ($core['version'] ?? null) !== $version) {
            throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_CORE_IDENTITY_INVALID');
        }
        if (is_array($identity)) {
            $reference = ReleaseDependencyIdentity::phpComposerReference($core, $identity, 'SCAFFOLD_TARGET_MODULE_SCHEMA_CORE_IDENTITY_INVALID');
        } elseif (!is_string($reference) || preg_match('/^[a-f0-9]{40}$/D', $reference) !== 1
            || ($core['source']['type'] ?? null) !== 'git') {
            throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_CORE_IDENTITY_INVALID');
        }

        $url = $core['dist']['url'] ?? '';
        if (is_string($url) && str_starts_with($url, '../packages/core-php/')) {
            $archivePath = substr($url, 3);
            if ($archivePath !== 'packages/core-php/peanut-admin-core-' . $version . '.zip'
                || ($core['dist']['type'] ?? null) !== 'zip'
                || ($core['dist']['reference'] ?? null) !== $reference
                || !is_string($core['dist']['shasum'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/D', $core['dist']['shasum']) !== 1) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_IDENTITY_INVALID');
            }
            // Read and hash before opening the archive; never extract or autoload it.
            $archiveBytes = $read($archivePath);
            ReleaseDependencyIdentity::verifyPhpArchiveBytes($core, $archiveBytes, 'SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_DIGEST_MISMATCH');
            $archive = new \PharData($release->artifactPath($files[$archivePath]));
            $entry = static function (string $path) use ($archive): string {
                if (!isset($archive[$path]) || !$archive[$path]->isFile() || $archive[$path]->isLink()
                    || $archive[$path]->getSize() > 1048576) {
                    throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_ENTRY_INVALID: ' . $path);
                }
                return $archive[$path]->getContent();
            };
            $composer = json_decode($entry('core/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($composer['name'] ?? null) !== 'peanut-admin/core'
                || ($composer['autoload']['psr-4']['PeanutAdmin\\Kernel\\'] ?? null) !== 'kernel/src/'
                || (isset($composer['version']) && $composer['version'] !== $version)) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_PACKAGE_INVALID');
            }
            $schema = $entry('core/kernel/resources/schemas/module-manifest.schema.json');
            if (!hash_equals(hash('sha256', $archiveBytes), hash_file('sha256', $release->artifactPath($files[$archivePath])))) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_CHANGED');
            }
        } else {
            // Registry releases can use the running validator only for that exact Core.
            if (!\Composer\InstalledVersions::isInstalled('peanut-admin/core')
                || ltrim((string) \Composer\InstalledVersions::getPrettyVersion('peanut-admin/core'), 'v') !== ltrim($version, 'v')
                || \Composer\InstalledVersions::getReference('peanut-admin/core') !== $reference) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_UNAVAILABLE: target Core archive or exact installed Core required');
            }
            $kernelRoot = dirname((new \ReflectionClass(\PeanutAdmin\Kernel\Module\ModuleProvider::class))->getFileName(), 3);
            $installedRoot = realpath((string) \Composer\InstalledVersions::getInstallPath('peanut-admin/core'));
            if ($installedRoot === false || realpath(dirname($kernelRoot)) !== $installedRoot) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_INSTALLED_IDENTITY_INVALID');
            }
            $schema = file_get_contents($kernelRoot . '/resources/schemas/module-manifest.schema.json');
        }
        if (!is_string($schema) || !is_object(json_decode($schema, false, 512, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_INVALID');
        }
        $schemaPath = $stage . '/target-module-manifest.schema.json';
        $this->writeFileAtomic($schemaPath, $schema, 0600);
        return new OpisManifestSchemaValidator($schemaPath);
    }

    /**
     * Check only customer Modules preserved into the target graph. This is intentionally
     * narrow: Kernel version constraints, explicit Kernel imports, and direct static calls.
     * It reports upgrade work; it never rewrites downstream source.
     *
     * @param array<string,mixed> $projection
     * @param list<string> $upstreamPluginKeys
     * @param array<string,string> $parameters
     * @param array<string,mixed> $versionContract
     * @return array<string,mixed>
     */
    private function customerModuleCompatibility(
        string $stage,
        array $projection,
        array $upstreamPluginKeys,
        ScaffoldManifest $release,
        array $parameters,
        array $versionContract,
    ): array {
        $kernelVersion = $this->targetModuleKernelVersion($release, $parameters, $versionContract);
        $matcher = new StrictVersionConstraintMatcher();
        $upstream = array_fill_keys($upstreamPluginKeys, true);
        $modules = [];
        $blockers = [];
        $checkedPhpFiles = 0;
        foreach (($projection['plugins'] ?? []) as $pluginKey => $plugin) {
            if (!is_string($pluginKey) || !is_array($plugin)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
            }
            if (isset($upstream[$pluginKey])) {
                continue;
            }
            $moduleKeys = $plugin['module_keys'] ?? null;
            $moduleRoots = $plugin['module_roots'] ?? null;
            if (!is_array($moduleKeys) || !array_is_list($moduleKeys)
                || !is_array($moduleRoots) || !array_is_list($moduleRoots)
                || count($moduleKeys) !== count($moduleRoots)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
            }
            foreach ($moduleKeys as $index => $moduleKey) {
                $moduleRoot = $moduleRoots[$index] ?? null;
                if (!is_string($moduleKey) || !is_string($moduleRoot)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
                }
                $manifestPath = ScaffoldPathGuard::projectPath($stage, $moduleRoot . '/module.json');
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 128, JSON_THROW_ON_ERROR);
                $constraint = is_array($manifest) ? ($manifest['kernel_constraint'] ?? null) : null;
                if (($manifest['key'] ?? null) !== $moduleKey || !is_string($constraint)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
                }
                $moduleBlockers = [];
                if (!$matcher->matches($kernelVersion, $constraint)) {
                    $moduleBlockers[] = [
                        'code' => 'MODULE_KERNEL_INCOMPATIBLE',
                        'module_key' => $moduleKey,
                        'path' => $moduleRoot . '/module.json',
                        'line' => null,
                        'symbol' => null,
                        'method' => null,
                        'detail' => "Target Module Kernel {$kernelVersion} does not satisfy {$constraint}.",
                    ];
                }
                [$referenceBlockers, $phpFiles] = $this->scanCustomerModuleKernelReferences(
                    $stage,
                    $moduleKey,
                    $moduleRoot,
                );
                array_push($moduleBlockers, ...$referenceBlockers);
                array_push($blockers, ...$moduleBlockers);
                $checkedPhpFiles += $phpFiles;
                $modules[] = [
                    'key' => $moduleKey,
                    'kernel_constraint' => $constraint,
                    'checked_php_files' => $phpFiles,
                    'status' => $moduleBlockers === [] ? 'ready' : 'blocked',
                ];
            }
        }
        usort($modules, static fn(array $left, array $right): int => strcmp($left['key'], $right['key']));
        usort($blockers, static fn(array $left, array $right): int => strcmp(
            implode('|', array_map('strval', [
                $left['module_key'] ?? '', $left['path'] ?? '', $left['line'] ?? 0,
                $left['code'] ?? '', $left['symbol'] ?? '', $left['method'] ?? '',
            ])),
            implode('|', array_map('strval', [
                $right['module_key'] ?? '', $right['path'] ?? '', $right['line'] ?? 0,
                $right['code'] ?? '', $right['symbol'] ?? '', $right['method'] ?? '',
            ])),
        ));
        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'kernel_version' => $kernelVersion,
            'customer_module_count' => count($modules),
            'checked_php_files' => $checkedPhpFiles,
            'modules' => $modules,
            'blockers' => $blockers,
            'scope' => 'kernel_constraint, explicit PeanutAdmin\\Kernel imports, and direct static calls; no automatic rewrite',
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:int} */
    private function scanCustomerModuleKernelReferences(string $stage, string $moduleKey, string $moduleRoot): array
    {
        $root = ScaffoldPathGuard::projectPath($stage, $moduleRoot);
        if (!is_dir($root) || is_link($root)) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
            }
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);
        $blockers = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $scan = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [
                    T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
                ], true)) {
                    $scan .= preg_replace('/[^\r\n]/', ' ', $token[1]) ?? '';
                } else {
                    $scan .= is_array($token) ? $token[1] : $token;
                }
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($stage) + 1));
            preg_match_all(
                '/^\s*use\s+(PeanutAdmin\\\\Kernel\\\\[A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/mi',
                $scan,
                $imports,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($imports as $import) {
                $symbol = $import[1][0];
                $segments = explode('\\', $symbol);
                $alias = ($import[2][0] ?? '') !== '' ? $import[2][0] : end($segments);
                $importLine = substr_count(substr($scan, 0, $import[1][1]), "\n") + 1;
                if (!$this->kernelSymbolExists($symbol)) {
                    $blockers[] = [
                        'code' => 'MODULE_CORE_CLASS_MISSING', 'module_key' => $moduleKey,
                        'path' => $relative, 'line' => $importLine, 'symbol' => $symbol, 'method' => null,
                        'detail' => 'Imported Peanut Module Kernel symbol is unavailable in the target Core.',
                    ];
                    continue;
                }
                preg_match_all(
                    '/\b' . preg_quote((string) $alias, '/') . '::([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                    $scan,
                    $calls,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
                );
                foreach ($calls as $call) {
                    $method = $call[1][0];
                    try {
                        $reflection = new \ReflectionMethod($symbol, $method);
                        $compatible = $reflection->isPublic() && $reflection->isStatic();
                    } catch (\ReflectionException) {
                        $compatible = false;
                    }
                    if (!$compatible) {
                        $blockers[] = [
                            'code' => method_exists($symbol, $method)
                                ? 'MODULE_CORE_METHOD_INCOMPATIBLE'
                                : 'MODULE_CORE_METHOD_MISSING',
                            'module_key' => $moduleKey,
                            'path' => $relative,
                            'line' => substr_count(substr($scan, 0, $call[0][1]), "\n") + 1,
                            'symbol' => $symbol,
                            'method' => $method,
                            'detail' => 'Direct static Kernel call is unavailable or no longer public/static in the target Core.',
                        ];
                    }
                }
            }
        }
        return [$blockers, count($files)];
    }

    private function kernelSymbolExists(string $symbol): bool
    {
        try {
            return class_exists($symbol)
                || interface_exists($symbol)
                || trait_exists($symbol)
                || (function_exists('enum_exists') && enum_exists($symbol));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,string> $parameters @param array<string,mixed> $versionContract */
    private function targetModuleKernelVersion(
        ScaffoldManifest $release,
        array $parameters,
        array $versionContract,
    ): string {
        $file = $release->files()['server/config/modules.php'] ?? null;
        if (!is_array($file)) {
            throw new RuntimeException('SCAFFOLD_MODULE_KERNEL_VERSION_UNAVAILABLE');
        }
        $contents = $this->renderCurrentVersionArtifact($release, $file, $parameters, $versionContract);
        if (preg_match(
            '/env\(\s*[\'"]PEANUT_MODULE_KERNEL_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            $contents,
            $match,
        ) !== 1 || !(new StrictVersionConstraintMatcher())->matches($match[1], $match[1])) {
            throw new RuntimeException('SCAFFOLD_MODULE_KERNEL_VERSION_UNAVAILABLE');
        }
        return $match[1];
    }

    /** @param array<string,mixed>|null $pluginTarget */
    private function moduleCompatibilityBlocked(?array $pluginTarget): bool
    {
        return is_array($pluginTarget)
            && (($pluginTarget['compatibility']['status'] ?? null) === 'blocked');
    }

    /** @param array<string,mixed>|null $pluginTarget */
    private function withModuleCompatibilityImpact(array $impact, ?array $pluginTarget): array
    {
        $compatibility = is_array($pluginTarget) ? ($pluginTarget['compatibility'] ?? null) : null;
        if (!is_array($compatibility)) {
            return $impact;
        }
        $impact['module_compatibility'] = $compatibility;
        if (($compatibility['status'] ?? null) === 'blocked') {
            $impact['message'] = sprintf(
                'No files will be changed until %d managed-file conflicts and %d customer Module compatibility blockers are resolved.',
                count($impact['must_resolve'] ?? []),
                count($compatibility['blockers'] ?? []),
            );
        }
        return $impact;
    }

    /** Verify the fixed raw template graph before deriving any APP-specific canonical identities. */
    private function validateRawPluginProjection(string $root, string $stage, ScaffoldManifest $release, array $target): array
    {
        $raw = $stage . '/raw-source';
        if (!mkdir($raw, 0700)) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_STAGE_FAILED');
        }
        $artifacts = ['plugins.lock' => true, 'server/resources/schemas/plugin.schema.json' => true];
        $roots = [];
        foreach ($target as $plugin) {
            $artifacts[$plugin['manifest']] = true;
            array_push($roots, ...$plugin['module_roots'], ...$plugin['frontend_roots']);
        }
        foreach ($release->files() as $path => $file) {
            if (!isset($artifacts[$path]) && !$this->withinAnyRoot($path, $roots)) {
                continue;
            }
            $bytes = file_get_contents($release->artifactPath($file));
            if (!is_string($bytes) || !hash_equals($file['template_sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('SCAFFOLD_ARTIFACT_DIGEST_MISMATCH: ' . $path);
            }
            $this->writeFileAtomic(ScaffoldPathGuard::projectPath($raw, $path), $bytes, (int) $file['mode']);
        }
        if (!is_file($raw . '/server/resources/schemas/plugin.schema.json')) {
            $this->copyPluginProjectionPath($root, $raw, 'server/resources/schemas/plugin.schema.json');
        }
        // checkLock regenerates the expected raw manifests and lock without replacing them.
        (new PluginArtifactWriter($raw . '/server'))->checkLock();
        $projection = $this->pluginProjection($raw, sourceApplication: false);
        if (array_keys($projection['plugins']) !== array_keys($target)) {
            throw new RuntimeException('SCAFFOLD_RAW_PLUGIN_TARGET_IDENTITY_MISMATCH');
        }
        foreach ($target as $key => $plugin) {
            foreach (['version', 'manifest', 'manifest_sha256', 'source_sha256', 'source_contract', 'module_keys', 'module_roots', 'frontend_roots'] as $field) {
                if ($projection['plugins'][$key][$field] !== $plugin[$field]) {
                    throw new RuntimeException('SCAFFOLD_RAW_PLUGIN_TARGET_IDENTITY_MISMATCH: ' . $key);
                }
            }
        }
        return $projection;
    }

    /** Compare complete Module contents without trusting a rewritten per-file digest or following a link. */
    private function pluginSourceFiles(string $root, array $roots): array
    {
        $files = [];
        foreach ($roots as $relative) {
            $source = ScaffoldPathGuard::projectPath($root, $relative);
            if (!is_dir($source) || is_link($source)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
                }
                if ($entry->isFile()) {
                    $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
                    $files[$path] = hash_file('sha256', $entry->getPathname());
                }
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** Reject every link and copy exact customer bytes/modes into the ephemeral projection. */
    private function copyPluginProjectionPath(string $root, string $stage, string $relative): void
    {
        $source = ScaffoldPathGuard::projectPath($root, $relative);
        if (is_file($source) && !is_link($source)) {
            $state = $this->regularFileState($source, $relative);
            $bytes = file_get_contents($source);
            if (!is_string($bytes) || !hash_equals($state['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED');
            }
            $this->writeFileAtomic(ScaffoldPathGuard::projectPath($stage, $relative), $bytes, (int) $state['mode']);
            return;
        }
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
            }
            if ($entry->isFile()) {
                $this->copyPluginProjectionPath($root, $stage, str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1)));
            }
        }
    }

    /** This directory is created by this preview and never holds recovery evidence. */
    private function removePluginProjectionStage(string $stage): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($stage);
    }

    /** Keep the installed customer Plugin graph whole; scaffold upgrades cannot partially replace its derived metadata or roots. */
    private function projectPluginBoundary(array $actions, array $current, array $target, array $official): array
    {
        $currentArtifacts = array_fill_keys($current['artifact_paths'], true);
        $currentRoots = $current['root_paths'];
        $targetArtifacts = [];
        $adoptionRequired = [];
        $officialArtifacts = [];
        $officialRoots = [];
        $managedArtifacts = [];
        $managedRoots = [];
        foreach ($official['keys'] as $key) {
            $officialArtifacts[$target[$key]['manifest']] = true;
            array_push($officialRoots, ...$target[$key]['module_roots'], ...$target[$key]['frontend_roots']);
            if (isset($official['managed_upstream_proofs'][$key])) {
                $managedArtifacts[$target[$key]['manifest']] = true;
                array_push($managedRoots, ...$target[$key]['module_roots'], ...$target[$key]['frontend_roots']);
            }
        }
        foreach ($target as $key => $plugin) {
            $targetArtifacts[$plugin['manifest']] = $key;
            $installed = $current['plugins'][$key] ?? null;
            if (!in_array($key, $official['keys'], true) && (!is_array($installed)
                || $installed['manifest'] !== $plugin['manifest']
                || array_diff($plugin['module_roots'], $installed['module_roots']) !== []
                || array_diff($plugin['frontend_roots'], $installed['frontend_roots']) !== [])) {
                $adoptionRequired[$key] = true;
            }
        }

        foreach ($actions as &$action) {
            $path = (string) $action['path'];
            if (isset($officialArtifacts[$path]) || $this->withinAnyRoot($path, $officialRoots)) {
                if (isset($official['manifest_contents'][$path])) {
                    $action['target_sha256'] = hash('sha256', $official['manifest_contents'][$path]);
                }
                // The production-verified installed graph is the source authority for a complete
                // official replacement, including earlier scaffold omissions. App-owned overrides
                // remain conflicts; edits outside canonical integrity never reach this projection.
                if ($action['reason'] !== 'app_owned_adoption_required') {
                    $present = ($action['current']['present'] ?? false) === true;
                    $action['action'] = is_string($action['target_sha256'] ?? null)
                        ? ($present ? (($action['current']['sha256'] === $action['target_sha256']
                            && $action['current']['mode'] === $action['mode']) ? 'preserve' : 'replace') : 'create')
                        : ($present ? 'delete' : 'omit');
                    $action['reason'] = isset($managedArtifacts[$path]) || $this->withinAnyRoot($path, $managedRoots)
                        ? 'managed_bundled_module_complete_projection' : 'official_module_complete_projection';
                    $action['conflict'] = false;
                }
                continue;
            }
            if ($path === 'plugins.lock' && $action['reason'] === 'app_owned_adoption_required') {
                continue;
            }
            if ($path === 'plugins.lock' && $adoptionRequired === []) {
                $digest = hash('sha256', $official['lock_contents']);
                $action['action'] = ($action['current']['sha256'] ?? null) === $digest ? 'preserve' : 'replace';
                $action['reason'] = 'official_plugin_lock_projection';
                $action['conflict'] = false;
                $action['target_sha256'] = $digest;
                continue;
            }
            if ($path === 'plugins.lock' && $adoptionRequired !== []) {
                $action['action'] = 'conflict';
                $action['reason'] = 'plugin_adoption_required';
                $action['conflict'] = true;
                continue;
            }
            if (isset($targetArtifacts[$path]) && !isset($currentArtifacts[$path])) {
                $action['action'] = 'conflict';
                $action['reason'] = 'plugin_adoption_required';
                $action['conflict'] = true;
                continue;
            }
            if (in_array($action['reason'], ['app_owned_adoption_required', 'managed_adoption_required'], true)) {
                continue;
            }
            if (isset($currentArtifacts[$path]) || $this->withinAnyRoot($path, $currentRoots)) {
                $present = ($action['current']['present'] ?? false) === true;
                $action['action'] = $present ? 'preserve' : 'omit';
                $action['reason'] = $present ? 'installed_plugin_projection' : 'installed_plugin_target_only_omitted';
                $action['conflict'] = false;
                $action['target_sha256'] = $present ? $action['current']['sha256'] : null;
                $action['baseline_source'] = $present ? 'validated-plugin-projection' : 'absent-plugin-projection';
            }
        }
        unset($action);
        return $actions;
    }

    private function assertPluginProjection(string $root, array $plan, bool $applied = false): void
    {
        $expected = $applied
            ? ($plan['identity']['plugin_target']['projection'] ?? $plan['identity']['plugin_projection'] ?? null)
            : ($plan['identity']['plugin_projection'] ?? null);
        if ($expected === null) {
            return;
        }
        if (!is_array($expected) || $this->pluginProjection($root, sourceApplication: true) !== $expected) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED');
        }
    }

    private function relativeDirectory(string $root, string $directory): string
    {
        $resolved = realpath($directory);
        if ($resolved === false
            || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_PATH_UNAVAILABLE');
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($root) + 1));
        ScaffoldPathGuard::projectPath($root, ScaffoldManifest::path($relative));
        return $relative;
    }

    /** @param list<string> $roots */
    private function withinAnyRoot(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $preservePaths @param list<string> $replacePaths */
    private function resolveConflicts(array $plan, string $confirmedPlanSha256, array $preservePaths, array $replacePaths): array
    {
        $actualPlanSha256 = 'sha256:' . hash('sha256', self::canonicalJson([$plan['identity'] ?? null, $plan['actions'] ?? null]));
        if (!hash_equals($actualPlanSha256, $confirmedPlanSha256)
            || (isset($plan['plan_sha256']) && !hash_equals((string) $plan['plan_sha256'], $actualPlanSha256))) {
            throw new RuntimeException('SCAFFOLD_RESOLUTION_CONFIRMATION_MISMATCH');
        }
        foreach ([$preservePaths, $replacePaths] as $paths) {
            if (array_filter($paths, static fn(mixed $path): bool => !is_string($path) || $path === '') !== []
                || count(array_unique($paths)) !== count($paths)) {
                throw new RuntimeException('SCAFFOLD_RESOLUTION_PATHS_INVALID');
            }
        }
        $preservePaths = array_values($preservePaths);
        $replacePaths = array_values($replacePaths);
        sort($preservePaths, SORT_STRING);
        sort($replacePaths, SORT_STRING);
        if (array_intersect($preservePaths, $replacePaths) !== []) {
            throw new RuntimeException('SCAFFOLD_RESOLUTION_PATHS_INVALID');
        }
        $conflictPaths = [];
        foreach ($plan['actions'] as $action) {
            if (($action['conflict'] ?? null) === true) {
                $conflictPaths[] = (string) $action['path'];
            }
        }
        sort($conflictPaths, SORT_STRING);
        $confirmedPaths = [...$preservePaths, ...$replacePaths];
        sort($confirmedPaths, SORT_STRING);
        if ($confirmedPaths !== $conflictPaths) {
            throw new RuntimeException('SCAFFOLD_RESOLUTION_PATHS_MISMATCH');
        }
        $preserve = array_fill_keys($preservePaths, true);
        $replace = array_fill_keys($replacePaths, true);
        $actions = $plan['actions'];
        foreach ($actions as &$action) {
            if (($action['conflict'] ?? null) !== true) {
                continue;
            }
            $path = (string) $action['path'];
            $selectedResolution = isset($preserve[$path]) ? 'preserve' : 'replace';
            if (isset($action['resolution_options'])
                && (!is_array($action['resolution_options'])
                    || !in_array($selectedResolution, $action['resolution_options'], true))) {
                throw new RuntimeException('SCAFFOLD_RESOLUTION_OPTION_UNSUPPORTED: ' . $path);
            }
            if (isset($preserve[$path])) {
                if (isset($action['rename_from'])) {
                    if (!in_array($action['rename_preserve'] ?? null, ['source', 'target'], true)) {
                        throw new RuntimeException('SCAFFOLD_RENAME_PRESERVE_INVALID: ' . $path);
                    }
                    $action['action'] = 'rename-preserve';
                    $action['reason'] = 'conflict_preserve_confirmed';
                    $action['conflict'] = false;
                    continue;
                }
                $ownershipTransition = ($action['reason'] ?? null) === 'recipe_ownership_transition_required'
                    && ($action['target_sha256'] ?? null) === null;
                $upstreamRemoved = ($action['reason'] ?? null) === 'project_modified_upstream_removed'
                    && ($action['target_sha256'] ?? null) === null;
                $action['action'] = $ownershipTransition || $upstreamRemoved ? 'omit' : 'preserve';
                $action['reason'] = $ownershipTransition
                    ? 'recipe_ownership_transition_confirmed'
                    : ($upstreamRemoved ? 'upstream_removed_preserve_confirmed' : 'conflict_preserve_confirmed');
                $action['conflict'] = false;
                continue;
            }
            if (isset($replace[$path]) && ($action['reason'] ?? null) === 'project_modified_upstream_removed'
                && ($action['target_sha256'] ?? null) === null) {
                $action['action'] = 'delete';
                $action['reason'] = 'conflict_upstream_delete_confirmed';
                $action['conflict'] = false;
                continue;
            }
            if (!isset($replace[$path]) || !is_string($action['target_sha256'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_RESOLUTION_PATHS_MISMATCH');
            }
            $action['action'] = isset($action['rename_from'])
                ? 'rename'
                : (($action['classification'] ?? null) === 'generated-managed' ? 'regenerate' : 'replace');
            $action['reason'] = 'conflict_replace_confirmed';
            $action['conflict'] = false;
        }
        unset($action);
        $resolved = $plan;
        $resolved['actions'] = $actions;
        $resolved['status'] = $this->moduleCompatibilityBlocked($resolved['identity']['plugin_target'] ?? null)
            ? 'blocked'
            : 'ready';
        $resolved['summary'] = $this->summary($actions);
        $resolved['impact'] = $this->withModuleCompatibilityImpact(
            $this->impact($actions),
            $resolved['identity']['plugin_target'] ?? null,
        );
        $resolved['resolution'] = [
            'from_candidate' => $plan['candidate'],
            'from_plan_sha256' => $actualPlanSha256,
            'preserve_paths' => $preservePaths,
            'replace_paths' => $replacePaths,
        ];
        $digest = hash('sha256', self::canonicalJson([$resolved['identity'], $resolved['actions']]));
        $resolved['candidate'] = 'scaffold-' . substr($digest, 0, 24);
        $resolved['plan_sha256'] = 'sha256:' . $digest;
        return $resolved;
    }

    private function summary(array $actions): array
    {
        $summary = ['total' => count($actions), 'automatic' => 0, 'preserved' => 0, 'conflicts' => 0];
        foreach ($actions as $action) {
            $action['conflict'] ? $summary['conflicts']++ : (in_array($action['action'], ['create','delete','replace','regenerate','rename'], true) ? $summary['automatic']++ : $summary['preserved']++);
        }
        return $summary;
    }

    private function impact(array $actions): array
    {
        $changes = [];
        $preserved = [];
        $conflicts = [];
        foreach ($actions as $action) {
            $item = ['path' => $action['path'], 'action' => $action['action'], 'reason' => $action['reason']];
            if (isset($action['rename_from'])) {
                $item['from'] = $action['rename_from'];
            }
            if (isset($action['resolution_options'])) {
                $item['resolution_options'] = $action['resolution_options'];
            }
            if ($action['conflict']) {
                $conflicts[] = $item;
            } elseif (in_array($action['action'], ['create', 'delete', 'replace', 'regenerate', 'rename', 'rename-preserve'], true)) {
                $changes[] = $item;
            } else {
                $preserved[] = $item;
            }
        }
        return [
            'message' => $conflicts === []
                ? sprintf('%d managed files will change; %d files will be preserved. Review the plan before apply.', count($changes), count($preserved))
                : sprintf('No files will be changed until %d conflicts are resolved.', count($conflicts)),
            'will_change' => $changes,
            'will_preserve' => $preserved,
            'must_resolve' => $conflicts,
            'ownership_notice' => 'App-owned files, third-party Modules and secrets are outside the automatic write set.',
        ];
    }

    /** @return array{APPLICATION_VERSION:string,PACKAGE_IDENTITY:string,PRODUCT_NAME:string,SLUG:string} */
    private function parameters(array $application, string $applicationVersion): array
    {
        return [
            'APPLICATION_VERSION' => $applicationVersion,
            'PACKAGE_IDENTITY' => (string) $application['application']['package_identity'],
            'PRODUCT_NAME' => (string) $application['application']['name'],
            'SLUG' => (string) $application['application']['slug'],
        ];
    }

    /**
     * Read the live application release authority without requiring installed
     * Composer dependencies and bind it to the currently adopted scaffold.
     *
     * @return array{0:array<string,int|string|null>,1:string}
     */
    private function versionContract(string $root, array $application): array
    {
        $path = ScaffoldPathGuard::projectPath($root, 'release-versions.json');
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_VERSION_CONTRACT_INVALID');
        }
        $raw = file_get_contents($path);
        $document = $this->normalizeVersionContractDocument(
            is_string($raw) ? $raw : '',
            'SCAFFOLD_VERSION_CONTRACT_INVALID',
        );
        if ($document['schema_version'] === 3) {
            $this->assertV3ArchiveDigests($root, $document['core_web']);
        }
        if ($document['scaffold_template'] !== ($application['template']['version'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_VERSION_CONTRACT_IDENTITY_MISMATCH');
        }
        return [$document, 'sha256:' . hash('sha256', (string) $raw)];
    }

    /** Compare the known version contract semantically while all other managed files remain byte-exact. */
    private function renderedContentMatches(
        string $root,
        string $path,
        array $current,
        string $expected,
    ): bool {
        if (($current['present'] ?? false) !== true) {
            return false;
        }
        if ($path !== 'release-versions.json') {
            return hash_equals(hash('sha256', $expected), (string) $current['sha256']);
        }
        $actual = file_get_contents(ScaffoldPathGuard::projectPath($root, $path));
        return is_string($actual)
            && hash_equals(hash('sha256', $actual), (string) $current['sha256'])
            && $this->normalizeVersionContractDocument($actual, 'SCAFFOLD_VERSION_CONTRACT_INVALID')
                === $this->normalizeVersionContractDocument($expected, 'SCAFFOLD_VERSION_CONTRACT_TARGET_INVALID');
    }

    /** Parse the supported historical/current fields and return their stable semantic order. */
    private function normalizeVersionContractDocument(string $raw, string $error): array
    {
        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
        $keys = is_array($document) ? array_keys($document) : [];
        $v3 = is_array($document)
            && ($document['schema_version'] ?? null) === 3
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v3';
        $v2 = is_array($document)
            && ($document['schema_version'] ?? null) === 2
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v2';
        $expectedKeys = $v3
            ? self::VERSION_CONTRACT_V3_KEYS
            : ($v2 ? self::VERSION_CONTRACT_V2_KEYS : self::VERSION_CONTRACT_V1_KEYS);
        if (!is_array($document) || count($keys) !== count($expectedKeys)
            || array_diff($keys, $expectedKeys) !== [] || array_diff($expectedKeys, $keys) !== []
            || (!$v2 && !$v3 && (($document['schema_version'] ?? null) !== 1
                || ($document['protocol'] ?? null) !== 'peanut.release-versions.v1'))) {
            throw new RuntimeException($error);
        }
        $normalized = [];
        foreach ($expectedKeys as $key) {
            $value = $document[$key];
            if ($key === 'instance_version' && $value === null) {
                $normalized[$key] = null;
                continue;
            }
            if ($v3 && in_array($key, ['core_php', 'core_web'], true)) {
                $normalized[$key] = $value;
                continue;
            }
            if (!in_array($key, ['schema_version', 'protocol'], true)
                && (!is_string($value)
                    || !(($v2 || $v3) ? $this->isStrictSemanticVersion($value) : $this->isSemanticVersion($value)))) {
                throw new RuntimeException($error . ': ' . $key);
            }
            $normalized[$key] = $value;
        }
        if ($v2 && ($normalized['source_product_version'] !== $normalized['core_php']
                || $normalized['source_product_version'] !== $normalized['core_web']
                || $normalized['source_product_version'] !== $normalized['scaffold_template'])) {
            throw new RuntimeException($error . ': product-core-version-mismatch');
        }
        if ($v3) {
            if ($normalized['source_product_version'] !== $normalized['scaffold_template']) {
                throw new RuntimeException($error . ': product-template-version-mismatch');
            }
            $this->assertV3DependencyIdentity($normalized['core_php'], $normalized['core_web'], $error);
        }
        return $normalized;
    }

    /** Resolve only the customer instance sequence used by scaffold apply/verify plans. */
    private function instanceVersion(array $versionContract): string
    {
        $version = $versionContract['schema_version'] >= 2
            ? ($versionContract['instance_version'] ?? null)
            : ($versionContract['product_release'] ?? null);
        if (!is_string($version)
            || !($versionContract['schema_version'] >= 2
                ? $this->isStrictSemanticVersion($version)
                : $this->isSemanticVersion($version))) {
            throw new RuntimeException('SCAFFOLD_INSTANCE_VERSION_INVALID');
        }
        return $version;
    }

    /** Accept the SemVer surface already supported by scaffold application identities. */
    private function isSemanticVersion(string $version): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', $version) === 1;
    }

    private function isStrictSemanticVersion(string $version): bool
    {
        return preg_match(self::STRICT_SEMVER, $version) === 1;
    }

    private function assertV3DependencyIdentity(mixed $php, mixed $web, string $error): void
    {
        ReleaseDependencyIdentity::validate($php, $web, $error . ': core_php', $error . ': core_web');
    }

    private function assertV3ArchiveDigests(string $root, array $web): void
    {
        ReleaseDependencyIdentity::verifyArchives($root, $web, 'SCAFFOLD_VERSION_CONTRACT_CORE_WEB_ARCHIVE_INVALID');
    }

    private function releaseIdentity(ScaffoldManifest $manifest): array
    {
        return $manifest->release() + ['manifest_sha256' => $manifest->digest()];
    }
    private function assertManifestDigest(ScaffoldManifest $manifest, string $digest): void
    {
        if (!hash_equals($digest, $manifest->digest())) {
            throw new RuntimeException('SCAFFOLD_MANIFEST_CHECKSUM_DRIFT');
        }
    }

    private function applicationManifest(string $root): array
    {
        $path = ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json');
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_MISSING');
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || !is_array($data['application'] ?? null) || !is_array($data['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_INVALID');
        }
        $protocol = $data['protocol'] ?? null;
        if (($data['schema_version'] ?? null) === 2 && $protocol === 'peanut.application-scaffold.v2') {
            $version = $data['application']['version'] ?? null;
        } elseif (($data['schema_version'] ?? null) === 1 && $protocol === 'peanut.application-scaffold.v1') {
            $version = $this->legacyApplicationVersion($root);
            $data['application']['version'] = $version;
        } else {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_INVALID');
        }
        if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', (string) $version) !== 1) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_VERSION_INVALID');
        }
        return [$data, 'sha256:' . hash('sha256', (string) $raw)];
    }

    private function legacyApplicationVersion(string $root): string
    {
        $path = ScaffoldPathGuard::projectPath($root, 'RELEASE_METADATA.json');
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_UNAVAILABLE');
        }
        try {
            $metadata = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_UNAVAILABLE', 0, $exception);
        }
        $candidates = [];
        if (is_array($metadata)
            && ($metadata['schema_version'] ?? null) === 2
            && ($metadata['protocol'] ?? null) === 'peanut.release-metadata.v2'
            && is_string($metadata['instance_version'] ?? null)) {
            $candidates[] = $metadata['instance_version'];
        }
        if (is_array($metadata) && is_string($metadata['version'] ?? null)) {
            $candidates[] = $metadata['version'];
        }
        if (is_array($metadata['application'] ?? null) && is_string($metadata['application']['version'] ?? null)) {
            $candidates[] = $metadata['application']['version'];
        }
        $candidates = array_values(array_unique($candidates, SORT_STRING));
        if (count($candidates) !== 1
            || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', $candidates[0]) !== 1) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_AMBIGUOUS');
        }
        return $candidates[0];
    }

    private function regularFileState(string $path, string $relative): array
    {
        if (!file_exists($path) && !is_link($path)) {
            return ['present' => false, 'sha256' => null, 'mode' => null];
        }
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_PATH_TYPE_REJECTED: ' . $relative);
        }
        $stat = lstat($path);
        if (!is_array($stat) || ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('SCAFFOLD_PATH_HARDLINK_REJECTED: ' . $relative);
        }
        return ['present' => true, 'sha256' => hash_file('sha256', $path), 'mode' => fileperms($path) & 0777];
    }

    private function actionState(string $root, array $actions): array
    {
        $files = [];
        foreach ($actions as $action) {
            $files[$action['path']] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $action['path']), $action['path']);
            if (isset($action['rename_from'])) {
                $source = (string) $action['rename_from'];
                $files[$source] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $source), $source);
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function ownershipState(string $root, array $application, string $classification): array
    {
        $files = [];
        foreach ($application['files'] as $file) {
            if (($file['classification'] ?? null) === $classification) {
                $files[$file['path']] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['path']), $file['path']);
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function loadPlan(string $root, string $path): array
    {
        $resolved = ScaffoldPathGuard::existingFileWithin($root, $path, 'SCAFFOLD_PLAN_PATH_INVALID');
        $data = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['protocol'] ?? null) !== 'peanut.scaffold-upgrade-plan.v2' || preg_match('/^scaffold-[a-f0-9]{24}$/D', (string) ($data['candidate'] ?? '')) !== 1) {
            throw new RuntimeException('SCAFFOLD_PLAN_INVALID');
        }
        $digest = hash('sha256', self::canonicalJson([$data['identity'] ?? null,$data['actions'] ?? null]));
        $expected = 'scaffold-' . substr($digest, 0, 24);
        if (!hash_equals($expected, $data['candidate'])
            || (isset($data['plan_sha256']) && !hash_equals('sha256:' . $digest, (string) $data['plan_sha256']))) {
            throw new RuntimeException('SCAFFOLD_PLAN_CHECKSUM_DRIFT');
        }
        $developmentKeys = [isset($data['identity']['source_selection']['source_checkout'])
            || (($data['identity']['source_selection']['source_identity']['kind'] ?? null) === 'local'),
            isset($data['identity']['development_source']), isset($data['identity']['source_relation'])];
        if (in_array(true, $developmentKeys, true)) {
            if (in_array(false, $developmentKeys, true)) { throw new RuntimeException('SCAFFOLD_DEVELOPMENT_PLAN_BINDING_CHANGED'); }
            $this->planTarget($root, $data);
        }
        $expectedStatus = count(array_filter($data['actions'], static fn(array $action): bool => ($action['conflict'] ?? null) === true)) === 0
            && !$this->moduleCompatibilityBlocked($data['identity']['plugin_target'] ?? null)
            ? 'ready'
            : 'blocked';
        if (($data['status'] ?? null) !== $expectedStatus) {
            throw new RuntimeException('SCAFFOLD_PLAN_STATUS_DRIFT');
        }
        $expectedImpact = $this->withModuleCompatibilityImpact(
            $this->impact($data['actions']),
            $data['identity']['plugin_target'] ?? null,
        );
        if (($data['impact'] ?? null) !== $expectedImpact) {
            throw new RuntimeException('SCAFFOLD_PLAN_IMPACT_DRIFT');
        }
        return $data;
    }

    private function loadAdoptionPlan(string $root, string $path): array
    {
        $resolved = ScaffoldPathGuard::existingFileWithin($root, $path, 'SCAFFOLD_ADOPTION_PLAN_PATH_INVALID');
        $data = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)
            || ($data['schema_version'] ?? null) !== 1
            || ($data['protocol'] ?? null) !== 'peanut.scaffold-ownership-adoption-plan.v1'
            || ($data['status'] ?? null) !== 'ready'
            || preg_match('/^ownership-adoption-[a-f0-9]{24}$/D', (string) ($data['candidate'] ?? '')) !== 1
            || !is_array($data['identity'] ?? null)
            || !is_array($data['actions'] ?? null)
            || !is_array($data['paths'] ?? null)
            || !is_array($data['metadata_writes'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_INVALID');
        }
        $paths = EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS;
        sort($paths, SORT_STRING);
        $candidateDigest = hash('sha256', self::canonicalJson([$data['identity'], $data['actions']]));
        $metadataWrites = ['.peanut/application-manifest.json'];
        foreach ($data['actions'] as $action) {
            if (!is_array($action) || !is_string($action['path'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_INVALID');
            }
            $metadataWrites[] = (string) ($action['baseline_path'] ?? '');
        }
        sort($metadataWrites, SORT_STRING);
        if ($data['paths'] !== $paths
            || array_column($data['actions'], 'path') !== $paths
            || $data['metadata_writes'] !== $metadataWrites
            || !hash_equals('ownership-adoption-' . substr($candidateDigest, 0, 24), $data['candidate'])
            || !hash_equals('sha256:' . $candidateDigest, (string) ($data['plan_sha256'] ?? ''))
            || !hash_equals('sha256:' . hash('sha256', self::canonicalJson($paths)), (string) ($data['paths_sha256'] ?? ''))) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_CHECKSUM_DRIFT');
        }
        return $data;
    }

    private function assertActionFresh(string $root, array $action): void
    {
        $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $action['path']), $action['path']);
        if ($actual !== $action['current']) {
            throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED: ' . $action['path']);
        }
        if (isset($action['rename_from'])) {
            $source = (string) $action['rename_from'];
            $actualSource = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $source), $source);
            if ($actualSource !== ($action['rename_from_current'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED: ' . $source);
            }
        }
    }

    /** Reject any manifest, version authority, managed, app-owned, or release drift since preflight. */
    private function assertPlanFresh(string $root, array $plan): void
    {
        [$application, $manifestDigest] = $this->applicationManifest($root);
        if (!hash_equals($plan['identity']['application_manifest_sha256'], $manifestDigest)) {
            throw new RuntimeException('SCAFFOLD_PLAN_APPLICATION_LOCK_CHANGED');
        }
        $this->assertPluginProjection($root, $plan);
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        if ($versionContract !== ($plan['identity']['version_contract'] ?? null)
            || $versionContractDigest !== ($plan['identity']['version_contract_sha256'] ?? null)
        ) {
            throw new RuntimeException('SCAFFOLD_PLAN_VERSION_CONTRACT_CHANGED');
        }
        $managed = $this->actionState($root, $plan['actions']);
        if (!hash_equals($plan['identity']['managed_pre_sha256'], $managed['digest'])) {
            throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED');
        }
        [$application] = $this->applicationManifest($root);
        $app = $this->ownershipState($root, $application, 'app-owned');
        if (!hash_equals($plan['identity']['app_owned_pre_sha256'], $app['digest'])) {
            throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED');
        }
        $this->assertManifestDigest(ScaffoldManifest::load($plan['manifest_paths']['from'], true), $plan['identity']['from']['manifest_sha256']);
        $this->assertManifestDigest(ScaffoldManifest::load($plan['manifest_paths']['to']), $plan['identity']['to']['manifest_sha256']);
    }

    /**
     * Revalidate the complete committed target before an idempotent apply/verify returns success.
     *
     * @return array{0:array<string,mixed>,1:array{digest:string,files:array<string,mixed>}}
     */
    private function assertAppliedState(string $root, array $plan): array
    {
        $result = $this->assertAppliedFiles($root, $plan);
        $this->assertPluginProjection($root, $plan, true);
        return $result;
    }

    /** Verify managed afterimages and application ownership against the applied plan. */
    private function assertAppliedFiles(string $root, array $plan): array
    {
        $retained = $this->retainedUpstreamDeletions($root, $plan);
        [$application] = $this->applicationManifest($root);
        $to = $this->planTarget($root, $plan);
        $this->assertManifestDigest($to, $plan['identity']['to']['manifest_sha256']);
        if (($application['template']['version'] ?? null) !== $to->version()
            || ScaffoldSourceIdentity::from($application['template'] ?? []) !== $to->sourceIdentity()
            || ($application['application']['version'] ?? null) !== $plan['identity']['application_version']
            || ($application['generation_source'] ?? null) !== ($plan['identity']['target_generation_source'] ?? null)
            || ($application['edition'] ?? null) !== ($plan['identity']['edition'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_VERIFY_APPLICATION_IDENTITY_MISMATCH');
        }
        $actualAppOwned = $this->ownershipState(
            $root,
            ['files' => array_values(array_filter(
                $application['files'],
                static fn(array $file): bool => $file['classification'] === 'app-owned',
            ))],
            'app-owned',
        );
        $originalAppOwned = $actualAppOwned['files'];
        foreach ($retained as $path => $_) {
            $entry = array_values(array_filter($application['files'], static fn(array $file): bool => $file['path'] === $path));
            if (count($entry) !== 1 || ($entry[0]['classification'] ?? null) !== 'app-owned'
                || ($entry[0]['owner'] ?? null) !== 'application' || ($entry[0]['source'] ?? null) !== $path
                || ($entry[0]['sha256'] ?? null) !== ($actualAppOwned['files'][$path]['sha256'] ?? null)
                || ($entry[0]['mode'] ?? null) !== ($actualAppOwned['files'][$path]['mode'] ?? null)
                || array_intersect(['baseline_path', 'baseline_sha256', 'baseline_mode'], array_keys($entry[0])) !== []) {
                throw new RuntimeException('SCAFFOLD_VERIFY_RETAINED_OWNERSHIP_MISMATCH: ' . $path);
            }
            unset($originalAppOwned[$path]);
        }
        if (!hash_equals($plan['identity']['app_owned_pre_sha256'], 'sha256:' . hash('sha256', self::canonicalJson($originalAppOwned)))) {
            throw new RuntimeException('SCAFFOLD_VERIFY_APP_OWNED_CHANGED');
        }
        foreach ($application['files'] as $file) {
            if (!in_array($file['classification'], ['managed', 'generated-managed'], true)) {
                continue;
            }
            $path = ScaffoldPathGuard::projectPath($root, $file['path']);
            if (!is_file($path) || is_link($path)
                || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $path))
                || ((fileperms($path) & 0777) !== ($file['mode'] ?? 0644))) {
                throw new RuntimeException('SCAFFOLD_VERIFY_MANAGED_MISMATCH: ' . $file['path']);
            }
        }
        return [$application, $actualAppOwned];
    }

    /** Rebuild the plan from its immutable manifests so edited actions cannot claim another ownership class or path. */
    private function assertPlanRebound(string $root, array $plan): void
    {
        $expected = $this->preview(
            $root,
            (string) $plan['manifest_paths']['from'],
            (string) $plan['manifest_paths']['to'],
            $plan['identity']['source_selection'] ?? null,
        );
        if (isset($plan['resolution'])) {
            $resolution = $plan['resolution'];
            if (!is_array($resolution)
                || !hash_equals((string) ($resolution['from_candidate'] ?? ''), (string) $expected['candidate'])
                || !hash_equals((string) ($resolution['from_plan_sha256'] ?? ''), (string) $expected['plan_sha256'])
                || !is_array($resolution['preserve_paths'] ?? null)
                || !is_array($resolution['replace_paths'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_PLAN_MANIFEST_REBIND_FAILED');
            }
            $expected = $this->resolveConflicts(
                $expected,
                (string) $expected['plan_sha256'],
                $resolution['preserve_paths'],
                $resolution['replace_paths'],
            );
        }
        if (!hash_equals((string) $expected['candidate'], (string) $plan['candidate'])
            || !hash_equals((string) $expected['plan_sha256'], (string) ($plan['plan_sha256'] ?? ''))) {
            throw new RuntimeException('SCAFFOLD_PLAN_MANIFEST_REBIND_FAILED');
        }
    }

    private function ledger(string $root): ScaffoldUpgradeLedger
    {
        return new ScaffoldUpgradeLedger(ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/ledger.ndjson'));
    }
    private function hasEvent(ScaffoldUpgradeLedger $ledger, string $candidate, string $operation, string $status): bool
    {
        foreach ($ledger->entries($candidate) as $entry) {
            if (($entry['operation'] ?? null) === $operation && ($entry['status'] ?? null) === $status) {
                return true;
            }
        } return false;
    }
    private function candidateState(ScaffoldUpgradeLedger $ledger, string $candidate): ?string
    {
        $entries = $ledger->entries($candidate);
        return $entries === [] ? null : (string) ($entries[array_key_last($entries)]['status'] ?? '');
    }

    /** A later preflight must never conceal an earlier mutation, failure or recovery. */
    private function onlyOwnReadyHistory(string $root, array $plan): bool
    {
        $identity = hash('sha256', self::canonicalJson($plan['identity']));
        foreach ($this->ledger($root)->entries($plan['candidate']) as $event) {
            if (($event['operation'] ?? null) !== 'preflight' || ($event['status'] ?? null) !== 'ready'
                || ($event['plan_sha256'] ?? null) !== $plan['plan_sha256']
                || ($event['identity_sha256'] ?? null) !== $identity) { return false; }
        }
        return $plan['status'] === 'ready';
    }
    private function event(array $plan, string $operation, string $status, ?string $pre, ?string $post, array $extra = []): array
    {
        return ['schema_version' => 2,'candidate' => $plan['candidate'],'operation' => $operation,'status' => $status,'from' => $plan['identity']['from'],'to' => $plan['identity']['to'],'pre_sha256' => $pre,'post_sha256' => $post,
            'plan_sha256' => $plan['plan_sha256'] ?? null, 'identity_sha256' => hash('sha256', self::canonicalJson($plan['identity']))]
            + $extra;
    }

    private function locked(string $projectRoot, callable $operation): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        return self::sourceProductLock($root, fn() => self::sourceLock(
            $root . '/.peanut/upgrades/project.lock', fn(): array => $operation($root)));
    }

    private function createRecovery(string $root, array $plan): string
    {
        $paths = ['.peanut/application-manifest.json'];
        foreach ($plan['actions'] as $action) {
            if (in_array($action['action'], ['create', 'replace', 'regenerate', 'delete', 'rename', 'rename-preserve'], true)) {
                $paths[] = $action['path'];
                if (isset($action['rename_from'])) { $paths[] = $action['rename_from']; }
            }
            if (in_array($action['action'], ['create', 'replace', 'regenerate', 'rename', 'rename-preserve', 'preserve'], true)) {
                $paths[] = '.peanut/scaffold-baseline/' . $plan['identity']['to']['version'] . '/files/' . $action['path'];
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        return $this->createRecoveryForPaths($root, $plan['candidate'], $paths);
    }

    private function createRecoveryForPaths(string $root, string $candidate, array $paths): string
    {
        $directory = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $candidate);
        ScaffoldPathGuard::ensureDirectory($directory . '/files');
        chmod($directory, 0700);
        chmod($directory . '/files', 0700);
        $files = [];
        foreach ($paths as $relative) {
            $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $relative), $relative);
            if ($state['present']) {
                $content = file_get_contents(ScaffoldPathGuard::projectPath($root, $relative));
                if (!is_string($content)) {
                    throw new RuntimeException('SCAFFOLD_BACKUP_READ_FAILED: ' . $relative);
                }
                $backupRelative = 'files/' . $relative;
                $backup = ScaffoldPathGuard::projectPath($directory, $backupRelative);
                if (file_exists($backup) || is_link($backup)) {
                    ScaffoldPathGuard::existingFileWithin($directory, $backup, 'SCAFFOLD_RECOVERY_BACKUP_INVALID');
                    if (file_get_contents($backup) !== $content) { throw new RuntimeException('SCAFFOLD_RECOVERY_PREIMAGE_CHANGED'); }
                } else { $this->writeFileAtomic($backup, $content, 0600); }
                $state['backup'] = $backupRelative;
            } else {
                $state['backup'] = null;
            }
            $files[$relative] = $state;
        }
        $recovery = ['schema_version' => 2,'protocol' => 'peanut.scaffold-recovery.v2','candidate' => $candidate,
            'pre_tree_sha256' => 'sha256:' . hash('sha256', self::canonicalJson($files)),'files' => $files];
        $path = $directory . '/recovery.json';
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if ($existing !== $recovery) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_COLLISION');
            }
        } else {
            $this->writeJsonAtomic($path, $recovery, 0600);
        }
        return $this->relative($root, $path);
    }

    private function recoverCandidate(string $root, array $plan, string $operation): array
    {
        $this->retainedUpstreamDeletions($root, $plan);
        $ledger = $this->ledger($root);
        $manifestPath = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json');
        if (!is_file($manifestPath)) {
            throw new RuntimeException('SCAFFOLD_RECOVERY_NOT_FOUND');
        }
        $recovery = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($recovery) || ($recovery['candidate'] ?? null) !== $plan['candidate'] || !is_array($recovery['files'] ?? null)
            || !hash_equals((string) ($recovery['pre_tree_sha256'] ?? ''), 'sha256:' . hash('sha256', self::canonicalJson($recovery['files'])))) {
            throw new RuntimeException('SCAFFOLD_RECOVERY_INVALID');
        }
        $already = $this->recoveryMatches($root, $recovery);
        if (!$already) {
            foreach ($recovery['files'] as $relative => $state) {
                $target = ScaffoldPathGuard::projectPath($root, (string) $relative);
                if ($state['present']) {
                    $backup = ScaffoldPathGuard::existingFileWithin(dirname($manifestPath), dirname($manifestPath) . '/' . $state['backup'], 'SCAFFOLD_RECOVERY_BACKUP_INVALID');
                    $content = file_get_contents($backup);
                    if (!is_string($content) || !hash_equals($state['sha256'], hash('sha256', $content))) {
                        throw new RuntimeException('SCAFFOLD_RECOVERY_BACKUP_DRIFT');
                    }
                    $this->writeFileAtomic($target, $content, (int) $state['mode']);
                } elseif (file_exists($target)) {
                    if (!is_file($target) || is_link($target)) {
                        throw new RuntimeException('SCAFFOLD_RECOVERY_PATH_COLLISION: ' . $relative);
                    }
                    unlink($target);
                    $this->pruneEmptyParents(dirname($target), $root);
                }
            }
            if (!$this->recoveryMatches($root, $recovery)) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_VERIFY_FAILED');
            }
        }
        $this->assertPluginProjection($root, $plan);
        if (!$this->hasEvent($ledger, $plan['candidate'], $operation, 'recovered')) {
            $ledger->append($this->event($plan, $operation, 'recovered', $plan['identity']['managed_pre_sha256'], $plan['identity']['managed_pre_sha256']));
        }
        return ['status' => 'recovered', 'candidate' => $plan['candidate'], 'tree_sha256' => $recovery['pre_tree_sha256'], 'idempotent' => $already];
    }

    /** Advance the scaffold identity and snapshot the current version used for this target rendering. */
    private function nextApplicationManifest(string $root, array $plan, ScaffoldManifest $to): array
    {
        [$application] = $this->applicationManifest($root);
        $retained = $this->retainedUpstreamDeletions($root, $plan);
        $oldByPath = [];
        foreach ($application['files'] as $file) {
            $oldByPath[$file['path']] = $file;
        }
        $managedPaths = array_column($plan['actions'], 'path');
        $files = [];
        foreach ($application['files'] as $file) {
            if (!in_array($file['classification'], ['managed','generated-managed'], true)) {
                $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['path']), $file['path']);
                $file['sha256'] = $state['sha256'];
                $file['mode'] = $state['mode'];
                $files[] = $file;
            }
        }
        foreach ($plan['actions'] as $action) {
            if (isset($retained[$action['path']])) {
                $files[] = ['path' => $action['path'], 'sha256' => $action['current']['sha256'],
                    'mode' => $action['current']['mode'], 'classification' => 'app-owned',
                    'owner' => 'application', 'source' => $action['path']];
                continue;
            }
            if (in_array($action['action'], ['delete', 'omit'], true)) {
                continue;
            }
            $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $action['path']), $action['path']);
            if (!$state['present']) {
                throw new RuntimeException('SCAFFOLD_APPLY_MANAGED_MISSING: ' . $action['path']);
            }
            $files[] = ['path' => $action['path'],'sha256' => $state['sha256'],'mode' => $state['mode'],'classification' => $action['classification'],
                'owner' => 'scaffold','source' => $action['path'],'baseline_path' => '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'],
                'baseline_sha256' => $action['target_sha256'],
                'baseline_mode' => ($action['baseline_source'] ?? null) === 'validated-plugin-projection'
                    ? $action['current']['mode'] : $to->files()[$action['path']]['mode']];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $managed = array_values(array_filter($files, static fn(array $f): bool => in_array($f['classification'], ['managed','generated-managed'], true)));
        $appOwned = array_values(array_filter($files, static fn(array $f): bool => $f['classification'] === 'app-owned'));
        $application['template'] = ['version' => $to->version(),'inventory_sha256' => $to->release()['inventory_sha256'],
            ...ScaffoldSourceIdentity::fields($to->sourceIdentity())];
        $application['generation_source'] = $plan['identity']['target_generation_source'];
        $edition = $plan['identity']['edition'] ?? null;
        if (is_array($edition)) {
            $application['edition'] = $edition;
        }
        $application['schema_version'] = 2;
        $application['protocol'] = 'peanut.application-scaffold.v2';
        $application['application']['version'] = $plan['identity']['application_version'];
        $application['ownership']['baseline_root'] = '.peanut/scaffold-baseline/' . $to->version() . '/files';
        $application['digests'] = ['managed_tree_sha256' => $this->manifestTreeDigest($managed),'app_owned_tree_sha256' => $this->manifestTreeDigest($appOwned)];
        $application['files'] = $files;
        $application['last_scaffold_upgrade'] = [
            'candidate' => $plan['candidate'],
            'from' => $plan['identity']['from']['version'],
            'to' => $to->version(),
        ];
        if (is_array($edition)) {
            $application['last_scaffold_upgrade']['edition_profile_sha256'] = $edition['source_sha256'];
            $application['last_scaffold_upgrade']['tenant_bootstrap'] = $edition['tenant_bootstrap'];
        }
        return $application;
    }

    /** A confirmed local retention has no incoming bytes or new upstream baseline. */
    private function retainedUpstreamDeletions(string $root, array $plan): array
    {
        $paths = [];
        foreach ($plan['actions'] as $action) {
            if (($action['action'] ?? null) === 'omit'
                && ($action['reason'] ?? null) === 'upstream_removed_preserve_confirmed') {
                $this->assertActionFresh($root, $action);
                $paths[$action['path']] = true;
            }
        }
        return $paths;
    }

    private function managedDigestFromManifest(string $root, array $manifest): string
    {
        $files = [];
        foreach ($manifest['files'] as $file) {
            if (in_array($file['classification'], ['managed','generated-managed'], true)) {
                $files[$file['path']] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['path']), $file['path']);
            }
        }
        ksort($files, SORT_STRING);
        return 'sha256:' . hash('sha256', self::canonicalJson($files));
    }
    private function manifestTreeDigest(array $files): string
    {
        $rows = array_map(static fn(array $f): string => $f['path'] . "\0" . $f['sha256'], $files);
        sort($rows, SORT_STRING);
        return hash('sha256', implode("\n", $rows));
    }
    private function recoveryMatches(string $root, array $recovery): bool
    {
        foreach ($recovery['files'] as $relative => $state) {
            $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, (string) $relative), (string) $relative);
            if ($actual['present'] !== $state['present'] || $actual['sha256'] !== $state['sha256'] || $actual['mode'] !== $state['mode']) {
                return false;
            }
        } return true;
    }

    private function writeFileAtomic(string $path, string $content, int $mode): void
    {
        ScaffoldPathGuard::ensureDirectory(dirname($path));
        $tmp = dirname($path) . '/.' . basename($path) . '.stage-' . bin2hex(random_bytes(6));
        $stream = fopen($tmp, 'x+b');
        if ($stream === false || !chmod($tmp, $mode) || fwrite($stream, $content) !== strlen($content)
            || !fflush($stream) || !fsync($stream)) {
            if (is_resource($stream)) { fclose($stream); }
            @unlink($tmp);
            throw new RuntimeException('SCAFFOLD_ATOMIC_WRITE_FAILED: ' . $path);
        }
        fclose($stream);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('SCAFFOLD_ATOMIC_WRITE_FAILED: ' . $path);
        }
        $directory = fopen(dirname($path), 'r');
        if ($directory === false || !fsync($directory)) { throw new RuntimeException('SCAFFOLD_DIRECTORY_SYNC_FAILED'); }
        fclose($directory);
    }
    private function pruneEmptyParents(string $directory, string $root): void
    {
        while ($directory !== $root && str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
            if (!is_dir($directory) || is_link($directory) || (scandir($directory) ?: []) !== ['.','..'] || !rmdir($directory)) {
                return;
            }
            $directory = dirname($directory);
        }
    }
    private function writeJsonAtomic(string $path, array $data, int $mode): void
    {
        $this->writeFileAtomic($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", $mode);
    }
    private function relative(string $root, string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
    }
    private static function canonicalJson(array $value): string
    {
        self::sortRecursive($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    private static function sortRecursive(array &$value): void
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        } foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
