<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Identity\Authorization\CatalogLifecycleService;
use PeanutAdmin\Modules\Settings\Service\SettingCatalogService;
use think\facade\Db;

/** Coordinates fixed owner operations and the existing deployment plan; does not access module tables. */
final readonly class ModuleCatalogMutationRepository
{
    public function __construct(
        private SettingCatalogService $settings,
        private CatalogLifecycleService $identity,
    ) {}

    /** @param array<string, ManifestDocument> $manifests */
    public function retireMissing(array $manifests): void
    {
        $this->identity->retireMissing($manifests);
    }

    /** @return list<string> */
    public function activeModuleKeys(): array
    {
        $keys = array_values(array_diff(array_unique([
            ...$this->identity->activeModuleKeys(),
            ...$this->settings->activeModuleKeys(),
        ]), ['core', 'platform']));
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * Stable plan labels are retained for existing callers; labels cannot select arbitrary storage.
     * @param list<string> $moduleKeys
     * @return array{removed:list<array<string,mixed>>,preserved:list<array<string,mixed>>,blockers:list<array<string,mixed>>}
     */
    public function plan(array $moduleKeys, bool $purge): array
    {
        $result = $this->identity->plan($moduleKeys, $purge);
        $result['removed'] = array_map($this->legacyIdentityEntry(...), $result['removed']);
        $result['preserved'] = array_map($this->legacyIdentityEntry(...), $result['preserved']);
        $settings = $this->settings->lifecycleReferences($moduleKeys);
        $this->append($result['removed'], 'pa_setting_definition', $purge ? 'delete' : 'soft_retire', $settings[$purge ? 'definitions' : 'active_definitions']);
        foreach ([
            'pa_setting_target_value' => 'target_values',
            'pa_setting_tenant_value' => 'tenant_values',
            'pa_setting_deployment_value' => 'deployment_values',
        ] as $label => $references) {
            if ($purge) {
                $this->append($result['removed'], $label, 'delete', $settings[$references]);
            } else {
                $this->append($result['preserved'], $label, 'preserve', $settings[$references], true);
            }
        }
        $this->sortEntries($result['removed']);
        $this->sortEntries($result['preserved']);
        return $result;
    }

    /** Both owners participate in one host transaction; this does not grant lifecycle permission.
     * @param list<string> $moduleKeys
     */
    public function retire(array $moduleKeys): void
    {
        Db::transaction(function () use ($moduleKeys): void {
            $this->identity->retire($moduleKeys);
            $this->settings->retire($moduleKeys);
        });
    }

    /** @param list<string> $moduleKeys */
    public function purge(array $moduleKeys): void
    {
        Db::transaction(function () use ($moduleKeys): void {
            $this->settings->purge($moduleKeys);
            $this->identity->purge($moduleKeys);
        });
    }

    /** Keeps the existing host plan representation without turning its labels into storage access. */
    private function legacyIdentityEntry(array $entry): array
    {
        $label = match ($entry['resource']) {
            'permissions' => 'pa_permission',
            'protected-resources' => 'pa_protected_resource',
            'target-types' => 'pa_target_type',
            'data-conditions' => 'pa_data_condition_definition',
            'menu-definitions' => 'pa_menu_definition',
            'resource-operations' => 'pa_resource_operation',
            'operation-permissions' => 'pa_resource_operation_permission',
            'operation-targets' => 'pa_resource_operation_target_type',
            'operation-conditions' => 'pa_resource_operation_condition',
            'tenant-role-grants' => 'pa_role_permission',
            'platform-role-grants' => 'pa_platform_role_permission',
            default => throw new \LogicException('MODULE_CATALOG_RESOURCE_UNDECLARED'),
        };
        return ['scope' => 'catalog', 'table' => $label, 'action' => $entry['action'], 'count' => $entry['count'], 'identifiers' => $entry['identifiers']];
    }

    private function append(array &$entries, string $table, string $action, array $ids, bool $includeEmpty = false): void
    {
        sort($ids, SORT_STRING);
        if ($ids !== [] || $includeEmpty) {
            $entries[] = ['scope' => 'catalog', 'table' => $table, 'action' => $action, 'count' => count($ids), 'identifiers' => $ids];
        }
    }

    private function sortEntries(array &$entries): void
    {
        usort($entries, static fn(array $a, array $b): int => strcmp($a['scope'] . "\0" . $a['table'] . "\0" . $a['action'], $b['scope'] . "\0" . $b['table'] . "\0" . $b['action']));
    }
}
