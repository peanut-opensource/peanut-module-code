<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Service;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionLoader;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionSynchronizer;
use think\facade\Db;

/**
 * Applies already selected deployment contributions using the existing definition rules.
 * The lifecycle caller retains authorization, dependency checks and its outer transaction.
 * Catalog metadata never includes stored deployment, Tenant or object setting values.
 */
final readonly class SettingCatalogService
{
    public function __construct(private SettingDefinitionSynchronizer $synchronizer) {}

    /** @param array<string, ManifestDocument> $manifests */
    public function synchronize(array $manifests, DateTimeImmutable $now): void
    {
        $registry = new SettingDefinitionRegistry();
        $loader = new SettingDefinitionLoader();
        foreach ($manifests as $key => $manifest) {
            if (!$manifest instanceof ManifestDocument || !is_string($key)
                || ($manifest->data['key'] ?? null) !== $key) {
                throw new ModuleException('MODULE_SETTING_OWNER_MISMATCH', 'A selected Module identity is inconsistent.');
            }
            ModuleKey::fromString($key);
            $backend = is_array($manifest->data['backend'] ?? null) ? $manifest->data['backend'] : [];
            $resource = $backend['setting_definitions'] ?? null;
            $definitions = is_string($resource)
                ? $loader->load($key, $this->resourcePath($manifest, $resource))
                : [];
            $registry->registerModule($key, $definitions);
        }
        $this->synchronizer->synchronize($registry, $now);
    }

    /** Fixed catalog fingerprint fields, not runtime setting values or an ORM object.
     * @return list<array<string, mixed>>
     */
    public function revisionRows(): array
    {
        return Db::name('setting_definition')
            ->field('id,module_key,setting_key,status,revision,definition_digest')->order('id')->select()->toArray();
    }

    /** @param list<string> $moduleKeys */
    public function activeCount(array $moduleKeys): int
    {
        if ($moduleKeys === []) {
            return 0;
        }
        foreach ($moduleKeys as $key) {
            ModuleKey::fromString($key);
        }
        return (int) Db::table('pa_setting_definition')
            ->whereIn('module_key', $moduleKeys)->where('status', 'active')->count();
    }

    /** @return list<string> */
    public function activeModuleKeys(): array
    {
        return array_map('strval', Db::name('setting_definition')->where('status', 'active')
            ->distinct(true)->order('module_key')->column('module_key'));
    }

    /**
     * Fixed references for an already authorized deployment lifecycle preview; no stored values.
     * @param list<string> $moduleKeys
     * @return array{definitions:list<string>,active_definitions:list<string>,deployment_values:list<string>,tenant_values:list<string>,target_values:list<string>}
     */
    public function lifecycleReferences(array $moduleKeys): array
    {
        $rows = $moduleKeys === [] ? [] : Db::name('setting_definition')
            ->whereIn('module_key', $moduleKeys)->field('id,status')->order('id')->select()->toArray();
        $definitions = array_map(static fn(array $row): string => (string) $row['id'], $rows);
        $active = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] === 'active'));
        return [
            'definitions' => $definitions,
            'active_definitions' => array_map(static fn(array $row): string => (string) $row['id'], $active),
            'deployment_values' => $this->valueReferences('setting_deployment_value', $definitions),
            'tenant_values' => $this->valueReferences('setting_tenant_value', $definitions),
            'target_values' => $this->valueReferences('setting_target_value', $definitions),
        ];
    }

    /**
     * Retires definitions while preserving every stored setting value.
     * The caller retains lifecycle authorization and its outer transaction.
     * @param list<string> $moduleKeys
     */
    public function retire(array $moduleKeys): void
    {
        if ($moduleKeys === []) {
            return;
        }
        Db::transaction(function () use ($moduleKeys): void {
            $ids = $this->definitionIds($moduleKeys);
            if ($ids !== []) {
                Db::name('setting_definition')->whereIn('id', $ids)->update([
                    'status' => 'retired', 'revision' => Db::raw('revision+1'),
                    'updated_at' => gmdate('Y-m-d H:i:s.v'),
                ]);
            }
        });
    }

    /**
     * Applies the already approved purge of selected definitions and their values.
     * Does not authorize purge, commit a caller transaction or accept table/column names.
     * @param list<string> $moduleKeys
     */
    public function purge(array $moduleKeys): void
    {
        if ($moduleKeys === []) {
            return;
        }
        Db::transaction(function () use ($moduleKeys): void {
            $ids = $this->definitionIds($moduleKeys);
            if ($ids === []) {
                return;
            }
            foreach (['setting_target_value', 'setting_tenant_value', 'setting_deployment_value'] as $table) {
                Db::name($table)->whereIn('definition_id', $ids)->delete();
            }
            Db::name('setting_definition')->whereIn('id', $ids)->delete();
        });
    }

    /** @param list<string> $moduleKeys @return list<string> */
    private function definitionIds(array $moduleKeys): array
    {
        return array_map('strval', Db::name('setting_definition')
            ->whereIn('module_key', $moduleKeys)->order('id')->column('id'));
    }

    /** The table is selected only by the three fixed calls above.
     * @param list<string> $definitionIds
     * @return list<string>
     */
    private function valueReferences(string $table, array $definitionIds): array
    {
        return $definitionIds === [] ? [] : array_map('strval', Db::name($table)
            ->whereIn('definition_id', $definitionIds)->order('id')->column('id'));
    }

    private function resourcePath(ManifestDocument $manifest, string $resource): string
    {
        if ($resource === '' || str_starts_with($resource, '/') || str_contains($resource, '\\')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $resource) === 1) {
            throw new ModuleException('MODULE_SETTING_RESOURCE_INVALID', 'A setting resource must stay inside its selected Module.');
        }
        $root = realpath($manifest->root);
        $path = $manifest->root . '/' . $resource;
        $resolved = realpath($path);
        if ($root === false || is_link($path)
            || ($resolved !== false && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            throw new ModuleException('MODULE_SETTING_RESOURCE_INVALID', 'A setting resource is outside its selected Module.');
        }
        return $path;
    }
}
