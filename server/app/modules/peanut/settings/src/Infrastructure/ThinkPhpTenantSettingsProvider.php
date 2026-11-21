<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Infrastructure;

use PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot;
use PeanutAdmin\Modules\Settings\Model\TenantApplicationSetting;
use think\facade\Db;

/** Tenant settings use the tenant_id schema in both Editions. */
final class ThinkPhpTenantSettingsProvider
{
    public function find(int $tenantId, string $namespace): ?TenantSettingSnapshot
    {
        $model = new TenantApplicationSetting();
        $query = Db::name('tenant_setting')->pk($model->getPk())->schema($model->getOption('schema'))->where('namespace', $namespace) // @phpstan-ignore peanut.rawDbQuery (The tenant settings owner matches tenant ID and namespace before projecting the document.)
            ->where('tenant_id', $tenantId);
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
        $query = Db::name('tenant_setting')->where('namespace', $namespace) // @phpstan-ignore peanut.rawDbQuery (The existing tenant and namespace row is selected with a lock before replacement.)
            ->where('tenant_id', $tenantId);
        $row = $query->lock(true)->find();
        $now = time();
        if (!is_array($row)) {
            $data = [
                'tenant_id' => $tenantId, 'namespace' => $namespace, 'config_json' => $encoded,
                'revision' => 1, 'create_time' => $now, 'update_time' => $now,
            ];
            Db::name('tenant_setting')->insert($data); // @phpstan-ignore peanut.rawDbQuery (The inserted settings row carries the selected tenant and namespace.)
            return new TenantSettingSnapshot($tenantId, $namespace, $document, 1, $now, $now);
        }
        $revision = (int) $row['revision'] + 1;
        $query = Db::name('tenant_setting') // @phpstan-ignore peanut.rawDbQuery (The update is scoped to the previously locked ID, namespace and tenant.)
            ->where('id', (int) $row['id'])
            ->where('namespace', $namespace)
            ->where('tenant_id', $tenantId);
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
