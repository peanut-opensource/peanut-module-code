<?php

declare(strict_types=1);

namespace app\platform\infrastructure\module;

use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;

/** A compiled deployment registry whose manifests must match active installation records. */
final readonly class DeployedTenantModuleRegistry
{
    /** @var array<string, ManifestDocument> */
    private array $manifests;

    public function __construct(
        private CompiledModuleRegistry $compiled,
        private TenantModuleStateQueries $installationStates = new TenantModuleStateQueries(),
    ) {
        if ($compiled->modules === []) {
            throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'No deployed Module manifest is registered.');
        }

        $manifests = [];
        foreach ($compiled->modules as $manifest) {
            $key = $manifest->data['key'] ?? null;
            $tenant = $manifest->data['tenant'] ?? null;
            if (!is_string($key)
                || $key === ''
                || isset($manifests[$key])
                || !is_array($tenant)
                || (($tenant['enableable'] ?? null) !== true
                    && !$compiled->isRequiredTenantFoundation($key))) {
                throw new ModuleException(
                    'MODULE_MANIFEST_INVALID',
                    'The deployment registry contains an invalid Tenant Module manifest.',
                );
            }
            $manifests[$key] = $manifest;
        }

        $revision = hash('sha256', implode('|', array_map(
            static fn(ManifestDocument $manifest): string => $manifest->digest,
            $compiled->modules,
        )));
        if (!hash_equals($revision, $compiled->revision)) {
            throw new ModuleException('MODULE_REGISTRY_INVALID', 'The deployment registry revision is invalid.');
        }
        $this->manifests = $manifests;
    }

    /**
     * Compile only explicitly deployed manifest roots. Missing or empty deployment input fails closed.
     *
     * @param non-empty-list<string> $moduleRoots
     */
    public static function compile(array $moduleRoots, ModuleRegistryCompiler $compiler): self
    {
        if ($moduleRoots === [] || !array_is_list($moduleRoots)) {
            throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'Deployed Module roots are unavailable.');
        }
        $loader = new ManifestLoader();
        $documents = [];
        foreach ($moduleRoots as $root) {
            if (!is_string($root) || trim($root) === '') {
                throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'A deployed Module root is invalid.');
            }
            $documents[] = $loader->load($root);
        }

        return new self($compiler->compile($documents));
    }

    public function compiled(): CompiledModuleRegistry
    {
        return $this->compiled;
    }

    public function isRequiredTenantFoundation(string $moduleKey): bool
    {
        return $this->compiled->isRequiredTenantFoundation($moduleKey);
    }

    public function requireInstalled(string $moduleKey, bool $lock = true): ManifestDocument
    {
        $manifest = $this->manifest($moduleKey);
        $this->assertInstallation($moduleKey, $manifest, $this->installationStates->installationIdentity($moduleKey, $lock));
        return $manifest;
    }

    /** @param list<string> $moduleKeys @return array<string, ManifestDocument> */
    public function requireInstalledMany(array $moduleKeys, bool $lock = true): array
    {
        $knownKeys = [];
        foreach ($moduleKeys as $moduleKey) {
            if (!isset($this->manifests[$moduleKey])) {
                break;
            }
            $knownKeys[] = $moduleKey;
        }
        $installations = $this->installationStates->installationIdentities($knownKeys, $lock);
        $manifests = [];
        foreach ($moduleKeys as $moduleKey) {
            $manifest = $this->manifest($moduleKey);
            $this->assertInstallation($moduleKey, $manifest, $installations[$moduleKey] ?? null);
            $manifests[$moduleKey] = $manifest;
        }
        return $manifests;
    }

    private function manifest(string $moduleKey): ManifestDocument
    {
        return $this->manifests[$moduleKey]
            ?? throw new ModuleException('MODULE_NOT_INSTALLED', "Unknown module: {$moduleKey}");
    }

    /** @param array<string,mixed>|null $installation */
    private function assertInstallation(string $moduleKey, ManifestDocument $manifest, ?array $installation): void
    {
        if ($installation === null) {
            throw new ModuleException('MODULE_NOT_INSTALLED', "Module {$moduleKey} is not installed.");
        }
        if (($installation['status'] ?? null) !== 'active') {
            throw new ModuleException('MODULE_INSTALLATION_FAILED', "Module {$moduleKey} is not active.");
        }
        if ((string) ($installation['installed_version'] ?? '') !== (string) ($manifest->data['version'] ?? '')
            || (int) ($installation['manifest_schema_version'] ?? 0) !== (int) ($manifest->data['schema_version'] ?? 0)
            || !hash_equals($manifest->digest, (string) ($installation['manifest_digest'] ?? ''))) {
            throw new ModuleException(
                'MODULE_INSTALLATION_MISMATCH',
                "Installed Module manifest does not match the deployment registry: {$moduleKey}",
            );
        }
    }
}
