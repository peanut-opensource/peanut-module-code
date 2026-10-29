<?php

declare(strict_types=1);

namespace app\platform\validation\plugin;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\value\plugin\PluginDescriptor;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use think\facade\Db;

/** Verifies that a full application release preserves or safely advances every installed Plugin. */
final readonly class PluginReleaseCompositionGuard
{
    /** @param array<string,mixed> $moduleConfig */
    public function __construct(
        private string $targetProjectRoot,
        private array $moduleConfig,
        private ModuleCatalogApplier $catalogs,
    ) {}

    /**
     * The active database, current release lock and staged target lock must agree before any code is replaced.
     *
     * @return array{status:string,checked:list<array<string,string>>}
     */
    public function verify(string $currentProjectRoot): array
    {
        $currentRoot = $this->projectRoot($currentProjectRoot, 'PLUGIN_RELEASE_CURRENT_ROOT_INVALID');
        $targetRoot = $this->projectRoot($this->targetProjectRoot, 'PLUGIN_RELEASE_TARGET_ROOT_INVALID');
        if ($this->sameDirectory($currentRoot, $targetRoot)) {
            throw new PluginLifecycleException(
                'PLUGIN_RELEASE_ROOTS_IDENTICAL',
                'Current and target application releases must be distinct directories.',
            );
        }

        $current = (new PluginLockResolver($currentRoot . '/server', $currentRoot . '/plugins.lock'))->all();
        $targetLock = $targetRoot . '/plugins.lock';
        $target = (new PluginLockResolver($targetRoot . '/server', $targetLock))->all();
        $targetConfig = $this->moduleConfig;
        $targetConfig['plugin_lock'] = $targetLock;
        $lifecycle = (new ThinkPhpModuleGovernanceProvider(
            $targetRoot . '/server',
            $targetConfig,
            $this->catalogs,
        ))->pluginLifecycle();

        $result = $this->compareLocked($current, $target);
        foreach ($result['checked'] as $item) {
            if ($item['operation'] === 'upgrade') {
                $lifecycle->upgrade($item['key'], true);
            }
        }
        return $result;
    }

    /** Shared read-only composition checks for full roots and verified server lock projections. */
    public function compareLocked(array $current, array $target): array
    {
        $checked = [];
        foreach ($this->installedPlugins() as $row) {
            $key = (string) $row['plugin_key'];
            if ((string) $row['status'] !== 'active') {
                throw new PluginLifecycleException(
                    'PLUGIN_RELEASE_STATE_INVALID',
                    "Plugin is not active during release replacement: {$key}",
                );
            }
            $currentPlugin = $current[$key] ?? throw new PluginLifecycleException(
                'PLUGIN_RELEASE_CURRENT_PACKAGE_MISSING',
                "Current release lock omits an installed Plugin: {$key}",
            );
            if (!$this->matchesInstallation($currentPlugin, $row)) {
                throw new PluginLifecycleException(
                    'PLUGIN_RELEASE_CURRENT_IDENTITY_MISMATCH',
                    "Current release lock differs from the installed Plugin identity: {$key}",
                );
            }

            $targetPlugin = $target[$key] ?? throw new PluginLifecycleException(
                'PLUGIN_RELEASE_PACKAGE_REMOVED',
                "Target application release omits an installed Plugin: {$key}",
            );
            $memberState = $this->memberState($key);
            $currentMembers = array_keys($currentPlugin->moduleRoots);
            $targetMembers = array_keys($targetPlugin->moduleRoots);
            sort($currentMembers, SORT_STRING);
            sort($targetMembers, SORT_STRING);
            if ($memberState['keys'] !== $currentMembers || $targetMembers !== $currentMembers) {
                throw new PluginLifecycleException(
                    'PLUGIN_RELEASE_PACKAGE_SCOPE_CHANGED',
                    "Application release changes the installed Plugin member set: {$key}",
                );
            }
            $comparison = version_compare($targetPlugin->version, $currentPlugin->version);
            if ($comparison < 0) {
                throw new PluginLifecycleException(
                    'PLUGIN_RELEASE_PACKAGE_DOWNGRADE',
                    "Target application release downgrades an installed Plugin: {$key}",
                );
            }
            if ($comparison === 0 && !$this->sameImmutableIdentity($currentPlugin, $targetPlugin)) {
                throw new PluginLifecycleException(
                    'PLUGIN_RELEASE_PACKAGE_IDENTITY_CHANGED',
                    "Target application release changes an installed Plugin without a version increase: {$key}",
                );
            }
            if ($memberState['mode'] === 'disabled' && $comparison !== 0) {
                throw new PluginLifecycleException(
                    'PLUGIN_RELEASE_DISABLED_PACKAGE_CHANGE',
                    "A disabled Plugin must be re-enabled before changing its package version: {$key}",
                );
            }
            $checked[] = [
                'key' => $key,
                'current_version' => $currentPlugin->version,
                'target_version' => $targetPlugin->version,
                'operation' => $memberState['mode'] === 'disabled'
                    ? 'preserve-disabled'
                    : ($comparison > 0 ? 'upgrade' : 'preserve'),
            ];
        }

        return ['status' => 'ready', 'checked' => $checked];
    }

    /** @return list<array<string,mixed>> */
    private function installedPlugins(): array
    {
        return Db::name('plugin_installation')->where('status', '<>', 'uninstalled')
            ->field('plugin_key,installed_version,source,artifact_sha256,composer_identity_json,npm_identity_json,frontend_identity_json,status')
            ->order('plugin_key')->select()->toArray();
    }

    /** Package updates preserve their complete Bundle scope and the explicit active/disabled state. */
    private function memberState(string $pluginKey): array
    {
        $keys = array_values(array_map('strval', Db::name('plugin_module')->where('plugin_key', $pluginKey)
            ->order('module_key')->column('module_key')));
        if ($keys === []) {
            throw new PluginLifecycleException(
                'PLUGIN_RELEASE_PACKAGE_SCOPE_INVALID',
                "Installed Plugin has no recorded Module members: {$pluginKey}",
            );
        }
        $states = (new TenantModuleStateQueries())->installationStates($keys);
        $active = 0;
        $disabled = 0;
        foreach ($keys as $moduleKey) {
            $state = $states[$moduleKey] ?? null;
            if (($state['status'] ?? null) === 'active' && ($state['last_error_code'] ?? null) === null) {
                $active++;
            } elseif (($state['status'] ?? null) === 'maintenance' && ($state['last_error_code'] ?? null) === null) {
                $disabled++;
            }
        }
        if ($active === count($keys)) {
            return ['keys' => $keys, 'mode' => 'active'];
        }
        if ($disabled === count($keys)) {
            return ['keys' => $keys, 'mode' => 'disabled'];
        }
        throw new PluginLifecycleException(
            'PLUGIN_RELEASE_MODULE_STATE_INVALID',
            "Plugin Module members are in a mixed or transitional state: {$pluginKey}",
        );
    }

    /** @param array<string,mixed> $row */
    private function matchesInstallation(PluginDescriptor $plugin, array $row): bool
    {
        return (string) $row['installed_version'] === $plugin->version
            && (string) $row['source'] === $plugin->source['type'] . ':' . $plugin->source['reference']
            && hash_equals((string) $row['artifact_sha256'], $plugin->source['sha256'])
            // lock_digest covers the whole lock and legitimately becomes stale after another package changes.
            && $this->jsonColumn($row['composer_identity_json'] ?? null) === $this->canonicalValue($plugin->composer)
            && $this->jsonColumn($row['npm_identity_json'] ?? null) === $this->canonicalValue($plugin->npm)
            && $this->jsonColumn($row['frontend_identity_json'] ?? null) === $this->canonicalValue($plugin->frontend);
    }

    /** Same-version releases may move only the enclosing application, never the Plugin package identity. */
    private function sameImmutableIdentity(PluginDescriptor $current, PluginDescriptor $target): bool
    {
        return $current->source === $target->source
            && hash_equals($current->manifestDigest, $target->manifestDigest)
            && $current->composer === $target->composer
            && $current->npm === $target->npm
            && $current->frontend === $target->frontend
            && array_keys($current->moduleRoots) === array_keys($target->moduleRoots)
            && $current->trust === $target->trust;
    }

    /** @return list<array<string,mixed>> */
    private function jsonColumn(mixed $value): array
    {
        if (!is_string($value)) {
            throw new PluginLifecycleException(
                'PLUGIN_RELEASE_CURRENT_IDENTITY_INVALID',
                'Installed Plugin package identity is unavailable.',
            );
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PluginLifecycleException(
                'PLUGIN_RELEASE_CURRENT_IDENTITY_INVALID',
                'Installed Plugin package identity is invalid: ' . $exception->getMessage(),
            );
        }
        if (!is_array($decoded) || !array_is_list($decoded) || !str_starts_with(ltrim($value), '[')) {
            throw new PluginLifecycleException(
                'PLUGIN_RELEASE_CURRENT_IDENTITY_INVALID',
                'Installed Plugin package identity must be a list.',
            );
        }
        return $this->canonicalValue($decoded);
    }

    /** Associative keys are canonicalized while package list order and scalar types remain significant. */
    private function canonicalValue(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalValue($item);
            }
        }
        return $value;
    }

    /** Resolves one complete release root without accepting symlinked identity inputs. */
    private function projectRoot(string $path, string $errorCode): string
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || is_link($path)
            || !is_dir($resolved . '/server') || is_link($resolved . '/server')
            || !is_file($resolved . '/plugins.lock') || is_link($resolved . '/plugins.lock')) {
            throw new PluginLifecycleException($errorCode, 'Application release root is invalid.');
        }
        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /** Uses filesystem identity so aliases cannot turn a self-comparison into a staged release check. */
    private function sameDirectory(string $left, string $right): bool
    {
        $leftStat = stat($left);
        $rightStat = stat($right);
        if (!is_array($leftStat) || !is_array($rightStat)) {
            throw new PluginLifecycleException(
                'PLUGIN_RELEASE_ROOT_IDENTITY_UNAVAILABLE',
                'Application release directory identity is unavailable.',
            );
        }
        return $leftStat['dev'] === $rightStat['dev'] && $leftStat['ino'] === $rightStat['ino'];
    }
}
