<?php

declare(strict_types=1);

namespace app\platform\infrastructure\module;

use app\platform\infrastructure\plugin\PluginLockResolver;

use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Authorization\CatalogLifecycleService;
use think\facade\Db;

/** Registers one explicitly deployed Module manifest in the deployment ledger. */
final readonly class DeploymentModuleInstaller
{
    public function __construct(
        private string $serverRoot,
        private ModuleCatalogApplier $catalogs,
        private CatalogLifecycleService $identityLifecycle = new CatalogLifecycleService(),
    ) {}

    /**
     * @param array<string,mixed> $deploymentConfig
     * @return array{key:string,version:string,schema:int,digest:string,status:string}
     */
    public function install(string $moduleKey, array $deploymentConfig): array
    {
        $registry = $this->registry($deploymentConfig);
        $manifest = null;
        foreach ($registry->compiled()->modules as $candidate) {
            if (($candidate->data['key'] ?? null) === $moduleKey) {
                $manifest = $candidate;
                break;
            }
        }
        if (!$manifest instanceof ManifestDocument) {
            throw new ModuleException('MODULE_NOT_REGISTERED', "Unknown deployed Module: {$moduleKey}");
        }

        return Db::transaction(function () use ($manifest, $registry, $moduleKey): array {
            $identity = $this->identityLifecycle->registerDeployedManifest($manifest, false);
            $this->catalogs->apply($registry->compiled(), [$moduleKey], false);
            $this->catalogs->invalidateTenantAuthorization([$moduleKey]);
            return $identity;
        });
    }

    /** @param array<string,mixed> $deploymentConfig */
    private function registry(array $deploymentConfig): DeployedTenantModuleRegistry
    {
        return (new ThinkPhpModuleGovernanceProvider(
            $this->serverRoot,
            $deploymentConfig,
            $this->catalogs,
            lockResolver: PluginLockResolver::forRuntime($this->serverRoot, (string) ($deploymentConfig['plugin_lock'] ?? '')),
        ))->registry();
    }
}
