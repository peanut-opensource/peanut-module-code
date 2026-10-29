<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Menu;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use PeanutAdmin\Modules\Identity\Module\Model\ModuleInstallation;
use PeanutAdmin\Modules\Identity\Module\Model\TenantModule;
use PeanutAdmin\Modules\Identity\Persistence\Model\MenuDefinition as MenuDefinitionRecord;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use think\model\type\Json;
use think\facade\Db;
use think\facade\Log;

final class ThinkPhpMenuCatalogRepository implements \PeanutAdmin\Kernel\Menu\MenuCatalogRepository
{
    public function synchronize(\PeanutAdmin\Kernel\Menu\MenuDefinition $definition, string $manifestDigest): void
    {
        $permissionId = $definition->requiredPermission === null
            ? null
            : $this->permissionId($definition->requiredPermission);
        try {
            $clientKeys = json_encode($definition->clientKeys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new DomainException('Menu client keys are not valid JSON.', 0, $exception);
        }
        $now = $this->now();
        $data = [
            'module_key' => $definition->moduleKey,
            'scope' => $definition->scope,
            'parent_key' => $definition->parentKey,
            'type' => $definition->type,
            'name' => $definition->name,
            'route_name' => $definition->routeName,
            'route_path' => $definition->routePath,
            'component_key' => $definition->componentKey,
            'icon' => $definition->icon,
            'sort_order' => $definition->sortOrder,
            'required_permission_id' => $permissionId,
            'client_keys_json' => $clientKeys,
            'status' => 'active',
            'is_show' => 1, 'is_cache' => 0, 'is_disable' => 0,
            'manifest_digest' => $manifestDigest,
            'updated_at' => $now,
        ];
        Db::transaction(function () use ($definition, $data, $now): void {
            $existing = MenuDefinitionRecord::where('key', $definition->key)->lock(true)->find();
            $defaults = $this->metadata($data);
            if ($existing === null) {
                MenuDefinitionRecord::create([
                    'key' => $definition->key, ...$data, 'created_at' => $now,
                    'upstream_defaults_json' => json_encode($defaults, JSON_THROW_ON_ERROR),
                    'menu_conflict_json' => null,
                ]);
                return;
            }
            $current = $this->metadata($existing->toArray());
            $baseline = $this->decodedJson($existing->getAttr('upstream_defaults_json'));
            // Unknown ownership is adopted only when no presentation or permission
            // field would change. Historical defaults are registered by the migration.
            $expected = $baseline ?? $defaults;
            $conflicts = array_keys(array_filter($current, static fn($value, $field): bool =>
                !array_key_exists($field, $expected) || $value !== $expected[$field], ARRAY_FILTER_USE_BOTH));
            if ($conflicts !== []) {
                $encoded = json_encode(['reason' => $baseline === null ? 'unknown_defaults' : 'customized', 'fields' => $conflicts], JSON_THROW_ON_ERROR);
                $changes = ['menu_conflict_json' => $encoded];
                // Lifecycle state remains managed even when presentation is
                // customized. An independently edited status stays protected.
                if ($baseline !== null && !in_array('status', $conflicts, true)
                    && $current['status'] !== $data['status']) {
                    $baseline['status'] = $data['status'];
                    $changes += ['status' => $data['status'], 'updated_at' => $now,
                        'upstream_defaults_json' => json_encode($baseline, JSON_THROW_ON_ERROR)];
                }
                if ($existing->getAttr('menu_conflict_json') !== $encoded || count($changes) > 1) {
                    $existing->save($changes);
                    Log::warning('MENU_DEFAULT_CONFLICT', ['key' => $definition->key, 'fields' => $conflicts]);
                }
                return;
            }
            $existing->save([
                ...$data,
                'upstream_defaults_json' => json_encode($defaults, JSON_THROW_ON_ERROR),
                'menu_conflict_json' => null,
            ]);
        });
    }

    public function retireMissing(array $activeKeys): void
    {
        if ($activeKeys === []) {
            throw new DomainException('The active menu catalog cannot be empty.');
        }
        Db::transaction(function () use ($activeKeys): void {
            foreach (MenuDefinitionRecord::where('status', 'active')->whereNotIn('key', $activeKeys)->lock(true)->select() as $row) {
                $baseline = $this->decodedJson($row->getAttr('upstream_defaults_json'));
                // A user-created or customized menu is never retired by discovery.
                if ($baseline === null || $this->metadata($row->toArray()) !== $baseline) {
                    continue;
                }
                $baseline['status'] = 'retired';
                $row->save(['status' => 'retired', 'updated_at' => $this->now(),
                    'upstream_defaults_json' => json_encode($baseline, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    /** @return array<string,mixed> */
    private function metadata(array $row): array
    {
        $fields = ['module_key', 'scope', 'parent_key', 'type', 'name', 'route_name', 'route_path',
            'component_key', 'icon', 'sort_order', 'required_permission_id', 'client_keys_json', 'status', 'is_show', 'is_cache', 'is_disable'];
        $metadata = array_intersect_key($row, array_fill_keys($fields, true));
        $metadata['sort_order'] = (int) $metadata['sort_order'];
        foreach (['is_show', 'is_cache', 'is_disable'] as $field) {
            $metadata[$field] = (int) ($metadata[$field] ?? ($field === 'is_show' ? 1 : 0));
        }
        $metadata['required_permission_id'] = $metadata['required_permission_id'] === null
            ? null : (int) $metadata['required_permission_id'];
        $metadata['client_keys_json'] = $this->decodedJson($metadata['client_keys_json']);
        // JSON objects have no key ordering; compare canonical field order.
        ksort($metadata, SORT_STRING);
        return $metadata;
    }

    private function decodedJson(mixed $value): ?array
    {
        if ($value instanceof Json) {
            $value = $value->value();
        }
        if (is_string($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new DomainException('Menu default metadata is invalid.');
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    public function activeDefinitions(string $scope): array
    {
        $rows = MenuDefinitionRecord::alias('menu')
            ->leftJoin('permission permission', 'permission.id = menu.required_permission_id')
            ->where('menu.scope', $scope)
            ->where('menu.status', 'active')
            ->field([
                'menu.key', 'menu.module_key', 'menu.scope', 'menu.parent_key', 'menu.type', 'menu.name',
                'menu.route_name', 'menu.route_path', 'menu.component_key', 'menu.icon', 'menu.sort_order',
                'permission.key' => 'required_permission', 'menu.client_keys_json',
            ])
            ->order('menu.sort_order')
            ->order('menu.key')
            ->select()
            ->toArray();

        return array_values(array_map(function (array $row): \PeanutAdmin\Kernel\Menu\MenuDefinition {
            $clientKeys = $row['client_keys_json'];
            if ($clientKeys instanceof Json) {
                $clientKeys = $clientKeys->value();
            } elseif (is_string($clientKeys)) {
                try {
                    $clientKeys = json_decode($clientKeys, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new DomainException('Stored menu client keys are invalid.', 0, $exception);
                }
            }
            if (!is_array($clientKeys) || !array_is_list($clientKeys)
                || array_filter($clientKeys, static fn(mixed $key): bool => !is_string($key)) !== []) {
                throw new DomainException('Stored menu client keys are invalid.');
            }

            return new \PeanutAdmin\Kernel\Menu\MenuDefinition(
                (string) $row['key'],
                (string) $row['module_key'],
                (string) $row['scope'],
                $row['parent_key'] === null ? null : (string) $row['parent_key'],
                (string) $row['type'],
                (string) $row['name'],
                $row['route_name'] === null ? null : (string) $row['route_name'],
                $row['route_path'] === null ? null : (string) $row['route_path'],
                $row['component_key'] === null ? null : (string) $row['component_key'],
                $row['required_permission'] === null ? null : (string) $row['required_permission'],
                $clientKeys,
                (int) $row['sort_order'],
                $row['icon'] === null ? null : (string) $row['icon'],
            );
        }, $rows));
    }

    public function activeDeploymentModules(): array
    {
        return array_values(array_map('strval', ModuleInstallation::where('status', 'active')
            ->order('module_key')
            ->column('module_key')));
    }

    public function activeTenantModules(int $tenantId): array
    {
        $now = $this->now();

        return array_values(array_map('strval', TenantModule::where('tenant_id', $tenantId)
            ->where('status', 'enabled')
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_at')->whereOr('effective_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', $now);
            })
            ->order('module_key')
            ->column('module_key')));
    }

    private function permissionId(string $key): int
    {
        $id = Permission::where('key', $key)->where('status', 'active')->value('id');

        return $id === null
            ? throw new DomainException("Menu permission is unavailable: {$key}")
            : (int) $id;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
