<?php

declare(strict_types=1);

namespace app\platform\services\plugin;

use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\PluginPackageInstaller;
use app\platform\policy\plugin\ModuleLifecyclePolicy;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use app\common\infrastructure\module\ModuleScaffoldGenerator;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use Composer\Semver\Semver;
use think\facade\Db;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Service\DeploymentModuleRequestService;
use PeanutAdmin\Modules\Ops\Service\PlatformModuleOperationExecutionService;

/** Application service shared by Platform HTTP adapters and module:* commands. */
final readonly class PlatformModuleRuntimeService
{
    /** @param array<string,mixed> $moduleConfig @param array<string,string> $trustedPublicKeys */
    public function __construct(
        private string $serverRoot,
        private array $moduleConfig,
        private array $trustedPublicKeys,
        private PluginRuntimeGovernanceService $governance,
        private PluginCatalogSyncService $catalog,
        private ModuleCatalogApplier $catalogs,
        private TenantModuleStateQueries $tenantStates,
        private DeploymentModuleRequestService $deliveryRequests,
        private PlatformModuleOperationExecutionService $moduleOperations,
    ) {}

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function modules(int $page, int $pageSize, ?string $moduleKey): array
    {
        $descriptors = PluginLockResolver::forRuntime($this->serverRoot, (string) ($this->moduleConfig['plugin_lock'] ?? '../plugins.lock'))->all();
        $details = [];
        $dependents = [];
        foreach ($descriptors as $descriptor) {
            $packageDetails = [];
            $packageProtected = false;
            foreach ($descriptor->moduleRoots as $key => $root) {
                $manifest = (new ManifestLoader())->load($root);
                $packageProtected = $packageProtected || ModuleLifecyclePolicy::isProtected($manifest);
                $dependencies = [];
                foreach ((array) ($manifest->data['dependencies'] ?? []) as $dependency) {
                    if (!is_array($dependency) || !is_string($dependency['module_key'] ?? null)) {
                        continue;
                    }
                    $dependencies[] = ['module_key' => $dependency['module_key'], 'version' => (string) ($dependency['version'] ?? '')];
                    $dependents[$dependency['module_key']][] = $key;
                }
                $packageDetails[$key] = [
                    'module_key' => $key,
                    'name' => (string) ($manifest->data['name'] ?? $key),
                    'version' => (string) ($manifest->data['version'] ?? ''),
                    'manifest_digest' => $manifest->digest,
                    'package_key' => $descriptor->key,
                    'package_version' => $descriptor->version,
                    'dependencies' => $dependencies,
                    'optional_dependencies' => array_values((array) ($manifest->data['optional_dependencies'] ?? [])),
                ];
            }
            $packageModules = array_keys($packageDetails);
            sort($packageModules, SORT_STRING);
            foreach ($packageDetails as $key => $detail) {
                $detail['package_modules'] = $packageModules;
                $detail['lifecycle_protected'] = $packageProtected;
                $details[$key] = $detail;
            }
        }
        $rows = Db::name('plugin_module')->alias('member')
            ->join('plugin_installation plugin', 'plugin.plugin_key=member.plugin_key')
            ->field('member.module_key,member.module_version,member.manifest_digest,member.plugin_key,plugin.installed_version AS package_version,plugin.status AS package_status,plugin.last_error_code AS package_error')
            ->order('member.module_key')->select()->toArray();
        $states = $this->tenantStates->installationStates(array_values(array_map(
            static fn(array $row): string => (string) $row['module_key'],
            $rows,
        )));
        $enabledCounts = $this->tenantStates->enabledCounts();
        foreach ($rows as $row) {
            $key = (string) $row['module_key'];
            $state = $states[$key] ?? null;
            $details[$key] ??= [
                'module_key' => $key,
                'name' => $key,
                'version' => (string) $row['module_version'],
                'manifest_digest' => (string) $row['manifest_digest'],
                'package_key' => (string) $row['plugin_key'],
                'package_version' => (string) $row['package_version'],
                'dependencies' => [],
                'optional_dependencies' => [],
                'package_modules' => [$key],
                'lifecycle_protected' => false,
            ];
            $details[$key]['status'] = $state['status'] ?? ($row['package_status'] === 'uninstalled' ? 'clean' : $row['package_status']);
            $details[$key]['installed_version'] = (string) $row['module_version'];
            $details[$key]['tenant_enabled_count'] = (int) ($enabledCounts[$key] ?? 0);
            $error = $state['last_error_code'] ?? $row['package_error'];
            $details[$key]['blockers'] = $error === null
                ? []
                : [(string) $error];
        }
        foreach ($details as $key => &$detail) {
            $detail['status'] ??= 'locked';
            $detail['tenant_enabled_count'] ??= 0;
            $detail['blockers'] ??= [];
            $detail['dependents'] = array_values(array_unique($dependents[$key] ?? []));
            sort($detail['dependents'], SORT_STRING);
        }
        unset($detail);
        foreach ($details as &$detail) {
            foreach (['dependencies', 'optional_dependencies'] as $field) {
                foreach ($detail[$field] as &$dependency) {
                    $target = $details[$dependency['module_key']] ?? null;
                    $reason = match (true) {
                        $target === null || !isset($target['installed_version']) => 'MODULE_NOT_INSTALLED',
                        $target['status'] !== 'active' => 'MODULE_NOT_ACTIVE',
                        !Semver::satisfies($target['installed_version'], $dependency['version']) => 'MODULE_VERSION_UNSATISFIED',
                        default => null,
                    };
                    $dependency['satisfied'] = $reason === null;
                    $dependency['unavailable_reason'] = $reason;
                }
                unset($dependency);
            }
            $detail['pending_actions'] = $detail['blockers'];
            foreach ($detail['dependencies'] as $dependency) {
                if (!$dependency['satisfied']) {
                    $detail['pending_actions'][] = $dependency['module_key'] . ': ' . $dependency['unavailable_reason'];
                }
            }
        }
        unset($detail);
        ksort($details, SORT_STRING);
        if ($moduleKey !== null) {
            $details = isset($details[$moduleKey]) ? [$moduleKey => $details[$moduleKey]] : [];
        }
        $total = count($details);
        return ['items' => array_slice(array_values($details), ($page - 1) * $pageSize, $pageSize), 'total' => $total];
    }

    /** @return array<string,mixed> */
    public function install(string $archivePath, ?string $expectedSha256, ?string $signatureKeyId,
        ?PlatformContext $context = null, ?string $idempotencyKey = null): array
    {
        if ($context !== null) {
            return $this->submitPackage($context, 'install', $archivePath, $expectedSha256, $signatureKeyId, $idempotencyKey);
        }
        $result = (new PluginPackageInstaller(
            $this->serverRoot,
            $this->moduleConfig,
            $this->trustedPublicKeys,
            $this->catalogs,
        ))
            ->install($archivePath, $expectedSha256, $signatureKeyId);
        $moduleKeys = array_values(array_map(static fn(array $module): string => (string) $module['module_key'], $result['modules'] ?? []));
        $catalog = $this->catalog();
        $operation = ($result['operation'] ?? null) === 'unchanged'
            ? 'unchanged'
            : (($result['operation'] ?? null) === 'installed' ? 'installed' : 'reactivated');
        if ($operation !== 'unchanged') {
            $catalog->invalidateTenantAuthorization($moduleKeys);
        }
        $result['operation'] = $operation;
        $result['catalog_revision'] = $catalog->catalogRevision();
        return $result;
    }

    /** @return array<string,mixed> */
    public function update(
        string $archivePath,
        ?string $expectedSha256,
        ?string $signatureKeyId,
        bool $dryRun,
        ?PlatformContext $context = null,
        ?string $idempotencyKey = null,
    ): array {
        if ($context !== null && !$dryRun) {
            return $this->submitPackage($context, 'update', $archivePath, $expectedSha256, $signatureKeyId, $idempotencyKey);
        }
        $result = (new PluginPackageInstaller(
            $this->serverRoot,
            $this->moduleConfig,
            $this->trustedPublicKeys,
            $this->catalogs,
        ))->update($archivePath, $expectedSha256, $signatureKeyId, $dryRun);
        if (!$dryRun && ($result['operation'] ?? null) !== 'unchanged') {
            $moduleKeys = array_values(array_map(
                static fn(array $module): string => (string) $module['module_key'],
                $result['modules'] ?? [],
            ));
            $catalog = $this->catalog();
            $catalog->invalidateTenantAuthorization($moduleKeys);
            $result['catalog_revision'] = $catalog->catalogRevision();
        }
        return $result;
    }

    private function submitPackage(PlatformContext $context, string $operation, string $path, ?string $sha256,
        ?string $keyId, ?string $idempotencyKey): array
    {
        if ($sha256 === null || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || $idempotencyKey === null || strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200
            || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1) {
            throw new PluginLifecycleException('MODULE_PACKAGE_REQUEST_INVALID', 'A package digest and Idempotency-Key are required.');
        }
        try {
            // A submission is scoped to its trusted operator, not permanently to an archive digest.
            $submissionDigest = hash('sha256', $context->operatorId . ':' . $idempotencyKey);
            $request = $this->deliveryRequests->prepareUpload($operation, $path, $sha256, $keyId, $submissionDigest);
            return $this->moduleOperations->submit($context, (string) $request['request_key'], $idempotencyKey);
        } catch (\RuntimeException $exception) {
            if (preg_match('/^OPS_MODULE_[A-Z0-9_]+$/D', $exception->getMessage()) === 1) {
                throw new PluginLifecycleException($exception->getMessage(), 'The Module worker is unavailable or rejected this package.');
            }
            throw $exception;
        }
    }

    public function task(PlatformContext $context, string $taskKey): array
    {
        return $this->moduleOperations->taskIfModuleOperation($context, $taskKey)
            ?? throw new PluginLifecycleException('MODULE_TASK_NOT_FOUND', 'Module operation was not found.');
    }

    /** @return array<string,mixed> */
    public function create(string $moduleKey, ?string $vendor = null, string $client = 'none'): array
    {
        return (new ModuleScaffoldGenerator(dirname($this->serverRoot)))->create($moduleKey, $vendor, $client);
    }

    /** @return array<string,mixed> */
    public function uninstallPreview(string $key, bool $purge): array
    {
        return $this->governance->preview($key, $purge);
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function uninstall(string $key, bool $purge, array $plan, string $digest): array
    {
        $result = $this->governance->uninstall($key, $purge, $plan, $digest);
        $moduleKeys = array_values(array_map(static fn(array $module): string => (string) $module['module_key'], $result['affected_modules'] ?? []));
        $catalog = $this->catalog();
        $catalog->invalidateTenantAuthorization($moduleKeys);
        return $result + ['catalog_revision' => $catalog->catalogRevision()];
    }

    /** @return array<string,mixed> */
    public function disable(string $moduleKey): array
    {
        $scope = $this->disableScope($moduleKey);
        $packageKey = $scope['package_key'];
        $moduleKeys = array_keys($scope['manifests']);
        $lockName = 'pa:module-runtime:' . substr(hash('sha256', $packageKey), 0, 40);
        $unchanged = null;
        try {
            (new AdvisoryLockExecution())->run($lockName, 0, function () use (
                $scope,
                $packageKey,
                $moduleKeys,
                &$unchanged,
            ): void {
                ModuleLifecyclePolicy::assertMutable($scope['manifests']);
                $statuses = $this->moduleStatuses($moduleKeys);
                if (count($statuses) !== count($moduleKeys)
                    || array_diff(array_values($statuses), ['active', 'maintenance']) !== []) {
                    throw new PluginLifecycleException('MODULE_STATE_INVALID', 'Every Bundle Module must be active or already disabled.');
                }
                if (count(array_filter($statuses, static fn(string $status): bool => $status === 'maintenance')) === count($moduleKeys)) {
                    $unchanged = [
                        'operation' => 'unchanged',
                        'package_key' => $packageKey,
                        'affected_modules' => $moduleKeys,
                        'status' => 'maintenance',
                        'catalog_revision' => $this->catalog()->catalogRevision(),
                    ];
                    return;
                }
                ModuleLifecyclePolicy::assertNoActiveBusinessDependents(
                    PluginLockResolver::forRuntime(
                        $this->serverRoot,
                        (string) ($this->moduleConfig['plugin_lock'] ?? '../plugins.lock'),
                    ),
                    $moduleKeys,
                );
                if ($this->tenantStates->hasEnabledModules($moduleKeys)) {
                    throw new PluginLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Disable every TenantModule in the Bundle first.');
                }
                Db::transaction(function () use ($moduleKeys): void {
                    $this->catalogs->retire($moduleKeys);
                    $this->catalogs->disableActivePackageModules($moduleKeys);
                });
            });
        } catch (AdvisoryLockUnavailable) {
            throw new PluginLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
        }
        if (is_array($unchanged)) {
            return $unchanged;
        }
        $catalog = $this->catalog();
        $catalog->invalidateTenantAuthorization($moduleKeys);
        return [
            'operation' => 'disabled',
            'package_key' => $packageKey,
            'affected_modules' => $moduleKeys,
            'status' => 'maintenance',
            'catalog_revision' => $catalog->catalogRevision(),
        ];
    }

    /** @return array<string,mixed> */
    public function sync(?string $moduleKey): array
    {
        $result = $this->catalog()->sync($moduleKey);
        if ($result['operation'] !== 'unchanged') {
            $this->catalog()->invalidateTenantAuthorization($result['modules']);
        }
        return $result;
    }

    private function catalog(): PluginCatalogSyncService
    {
        return $this->catalog;
    }

    /** @return array{package_key:string,manifests:array<string,\PeanutAdmin\Kernel\Module\ManifestDocument>} */
    private function disableScope(string $moduleKey): array
    {
        $packageKey = Db::name('plugin_module')->where('module_key', $moduleKey)->value('plugin_key');
        if (!is_string($packageKey) || $packageKey === '') {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Module package is not installed.');
        }
        $descriptor = (PluginLockResolver::forRuntime(
            $this->serverRoot,
            (string) ($this->moduleConfig['plugin_lock'] ?? '../plugins.lock'),
        ))->require($packageKey);
        $manifests = [];
        foreach ($descriptor->moduleRoots as $key => $root) {
            $manifests[$key] = (new ManifestLoader())->load($root);
        }
        ksort($manifests, SORT_STRING);
        return ['package_key' => $packageKey, 'manifests' => $manifests];
    }

    /** @param list<string> $moduleKeys @return array<string,string> */
    private function moduleStatuses(array $moduleKeys): array
    {
        $statuses = [];
        foreach ($this->tenantStates->installationStates($moduleKeys) as $moduleKey => $state) {
            $statuses[$moduleKey] = (string) ($state['status'] ?? '');
        }
        return $statuses;
    }

}
