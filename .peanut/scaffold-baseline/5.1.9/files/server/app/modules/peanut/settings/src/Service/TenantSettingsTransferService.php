<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Service;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsTransfer;
use think\facade\Db;

/** Owns scoped transfer of tenant_setting documents, including optimistic conflicts. */
final class TenantSettingsTransferService implements TenantSettingsTransfer
{
    public function snapshot(TenantContext $context): array
    {
        $tenantId = $this->tenantId($context);
        $snapshots = [];
        foreach (Db::name('tenant_setting')->where('tenant_id', $tenantId)
            ->field('namespace,config_json,revision,create_time,update_time')->order('namespace')->select()->toArray() as $row) {
            $snapshots[] = $this->state($tenantId, $row);
        }
        return $snapshots;
    }

    public function current(TenantContext $context, string $namespace): ?TenantSettingSnapshot
    {
        $tenantId = $this->tenantId($context);
        $this->assertNamespace($namespace);
        $row = Db::name('tenant_setting')->where('tenant_id', $tenantId)->where('namespace', $namespace)
            ->field('namespace,config_json,revision,create_time,update_time')->find();
        return is_array($row) ? $this->state($tenantId, $row) : null;
    }

    public function apply(TenantContext $context, string $namespace, array $document, ?int $revision): void
    {
        $tenantId = $this->tenantId($context);
        $this->assertNamespace($namespace);
        try {
            $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new \RuntimeException('TRANSFER_TENANT_SETTING_INVALID');
        }

        // Keep the same connection and nested transaction semantics as package application.
        // A later adapter or audit failure must still roll back this write.
        Db::transaction(function () use ($tenantId, $namespace, $encoded, $revision): void {
            $row = Db::name('tenant_setting')->where('tenant_id', $tenantId)->where('namespace', $namespace)
                ->field('id,revision,create_time')->lock(true)->find();
            $now = time();
            if (!is_array($row)) {
                if ($revision !== null) {
                    throw new \RuntimeException('TRANSFER_CONFLICT');
                }
                Db::name('tenant_setting')->insert([
                    'tenant_id' => $tenantId,
                    'namespace' => $namespace,
                    'config_json' => $encoded,
                    'revision' => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                return;
            }
            if ($revision === null || (int) $row['revision'] !== $revision) {
                throw new \RuntimeException('TRANSFER_CONFLICT');
            }
            $updated = Db::name('tenant_setting')->where('id', (int) $row['id'])
                ->where('tenant_id', $tenantId)->where('namespace', $namespace)->where('revision', $revision)->update([
                    'config_json' => $encoded,
                    'revision' => Db::raw('revision + 1'),
                    'update_time' => $now,
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('TRANSFER_CONFLICT');
            }
        });
    }

    private function tenantId(TenantContext $context): int
    {
        if ($context->tenantId < 1 || $context->accountId < 1 || $context->memberId < 1
            || $context->authorizationRevision < 1 || $context->sessionKey === ''
            || $context->clientKey === '' || $context->requestId === '') {
            throw new \RuntimeException('TRANSFER_TENANT_CONTEXT_INVALID');
        }
        return $context->tenantId;
    }

    /** @param array<string, mixed> $row */
    private function state(int $tenantId, array $row): TenantSettingSnapshot
    {
        try {
            $document = json_decode((string) ($row['config_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('TRANSFER_TENANT_SETTING_INVALID');
        }
        if (!is_array($document)) {
            throw new \RuntimeException('TRANSFER_TENANT_SETTING_INVALID');
        }
        return new TenantSettingSnapshot(
            $tenantId,
            (string) ($row['namespace'] ?? ''),
            $document,
            (int) ($row['revision'] ?? 0),
            (int) ($row['create_time'] ?? 0),
            (int) ($row['update_time'] ?? 0),
        );
    }

    private function assertNamespace(string $namespace): void
    {
        if (preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $namespace) !== 1) {
            throw new \RuntimeException('TRANSFER_TENANT_SETTING_INVALID');
        }
    }
}
