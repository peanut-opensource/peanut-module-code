<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Infrastructure;

use PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsProvider;
use app\common\tenancy\DataScopePolicy;
use think\facade\Db;

/**
 * TENANT_SETTING_SCOPE_EXCEPTION: the sole explicit tenant_id query allowed by
 * AGENT_EXECUTION_RULES.md §6.2 for the dual-Edition tenant_setting schema.
 */
final class ThinkPhpTenantSettingsProvider implements TenantSettingsProvider
{
    public function __construct(private readonly DataScopePolicy $dataScopePolicy) {}

    public function find(int $tenantId, string $namespace): ?TenantSettingSnapshot
    {
        $query = Db::name('tenant_setting')->where('namespace', $namespace);
        if ($this->dataScopePolicy->usesTenantColumn()) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->find();
        return is_array($row) ? $this->snapshot($row, $tenantId) : null;
    }

    public function replace(int $tenantId, string $namespace, array $document): TenantSettingSnapshot
    {
        return Db::transaction(
            fn(): TenantSettingSnapshot => $this->replaceLocked($tenantId, $namespace, $document),
        );
    }

    private function replaceLocked(int $tenantId, string $namespace, array $document): TenantSettingSnapshot
    {
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $query = Db::name('tenant_setting')->where('namespace', $namespace);
        if ($this->dataScopePolicy->usesTenantColumn()) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->lock(true)->find();
        $now = time();
        if (!is_array($row)) {
            $data = [
                'namespace' => $namespace, 'config_json' => $encoded,
                'revision' => 1, 'create_time' => $now, 'update_time' => $now,
            ];
            if ($this->dataScopePolicy->usesTenantColumn()) {
                $data['tenant_id'] = $tenantId;
            }
            Db::name('tenant_setting')->insert($data);
            return new TenantSettingSnapshot($tenantId, $namespace, $document, 1, $now, $now);
        }
        $revision = (int) $row['revision'] + 1;
        $query = Db::name('tenant_setting')
            ->where('id', (int) $row['id'])
            ->where('namespace', $namespace);
        if ($this->dataScopePolicy->usesTenantColumn()) {
            $query->where('tenant_id', $tenantId);
        }
        $query->update([
            'config_json' => $encoded, 'revision' => $revision, 'update_time' => $now,
        ]);
        return new TenantSettingSnapshot($tenantId, $namespace, $document, $revision, (int) $row['create_time'], $now);
    }

    /** @param array<string, mixed> $row */
    private function snapshot(array $row, int $tenantId): TenantSettingSnapshot
    {
        $document = json_decode((string) ($row['config_json'] ?? ''), true);
        if (!is_array($document)) {
            throw new \RuntimeException('租户设置数据无效');
        }
        return new TenantSettingSnapshot($tenantId, (string) $row['namespace'], $document, (int) $row['revision'], (int) $row['create_time'], (int) $row['update_time']);
    }
}
