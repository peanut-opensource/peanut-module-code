<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Module;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use think\facade\Db;

/** Shared commercial ceiling; it never grants a member a role or permission. */
final readonly class TenantEntitlementService
{
    public function __construct(private CompiledModuleRegistry $registry) {}

    public function license(int $tenantId, bool $lock = false): ?array
    {
        $row = Db::name('tenant_license')->alias('license')
            ->join('plan plan', 'plan.id = license.plan_id')->where('license.tenant_id', $tenantId)
            ->field('license.*,plan.code AS plan_code,plan.name AS plan_name,plan.revision AS plan_revision')
            ->lock($lock)->find();
        if ($row === null) {
            return null;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $effectiveAt = new DateTimeImmutable((string) $row['effective_at'], new DateTimeZone('UTC'));
        $expiresAt = $row['expires_at'] === null ? null : new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC'));
        $state = $row['status'] === 'suspended' ? 'suspended'
            : ($effectiveAt > $now ? 'upcoming' : ($expiresAt !== null && $expiresAt <= $now ? 'expired' : 'active'));
        return [
            'id' => (int) $row['id'], 'tenant_id' => (int) $row['tenant_id'], 'plan_id' => (int) $row['plan_id'],
            'plan_code' => (string) $row['plan_code'], 'plan_name' => (string) $row['plan_name'],
            'status' => (string) $row['status'], 'effective_at' => $effectiveAt->format('Y-m-d\TH:i:s.v\Z'),
            'expires_at' => $expiresAt?->format('Y-m-d\TH:i:s.v\Z'), 'revision' => (int) $row['revision'],
            'effective' => $state === 'active', 'state' => $state,
        ];
    }

    /** @return list<string> */
    public function moduleKeys(int $tenantId, bool $lock = false): array
    {
        $license = $this->license($tenantId, $lock);
        $selected = $license !== null && $license['effective']
            ? Db::name('plan_module')->where('plan_id', $license['plan_id'])->lock($lock)->column('module_key') : [];
        $keys = [];
        foreach ($this->registry->modules as $manifest) {
            $key = (string) $manifest->data['key'];
            if ($this->registry->isRequiredTenantFoundation($key) || in_array($key, $selected, true)) {
                $keys[] = $key;
            }
        }
        return array_values(array_map('strval', Db::name('module_installation')->where('status', 'active')
            ->whereIn('module_key', $keys)->order('module_key')->column('module_key')));
    }

    /** @return list<string> */
    public function permissionKeys(int $tenantId, bool $lock = false): array
    {
        $license = $this->license($tenantId, $lock);
        $keys = $license !== null && $license['effective']
            ? Db::name('plan_permission')->alias('detail')
                ->join('permission permission', 'permission.key = detail.permission_key')
                ->where('detail.plan_id', $license['plan_id'])->where('permission.status', 'active')
                ->whereNotLike('permission.key', 'platform.%')
                ->whereIn('permission.module_key', ['core', 'peanut.admin', 'instance.tenant', ...$this->moduleKeys($tenantId, $lock)])
                ->lock($lock)->column('permission.key') : [];
        return array_values(array_unique([...CorePermissionCatalog::TENANT, ...array_map('strval', $keys)]));
    }

    public function allowsModule(int $tenantId, string $moduleKey, ?DateTimeImmutable $now = null, bool $lock = false): bool
    {
        if ($this->registry->isRequiredTenantFoundation($moduleKey)) {
            return true;
        }
        $license = $this->license($tenantId, $lock);
        if ($license === null || $license['status'] !== 'active') {
            return false;
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new DateTimeImmutable($license['effective_at']) <= $now
            && ($license['expires_at'] === null || $now < new DateTimeImmutable($license['expires_at']))
            && Db::name('plan_module')->where('plan_id', $license['plan_id'])->where('module_key', $moduleKey)->lock($lock)->find() !== null;
    }

    /** A shared plan revision and the live time state invalidate existing authorization caches. */
    public function revisionFacts(int $tenantId, bool $lock = false): array
    {
        $license = $this->license($tenantId, $lock);
        if ($license === null) {
            return [];
        }
        return [$license, Db::name('plan')->where('id', $license['plan_id'])->lock($lock)->value('revision'),
            $this->moduleKeys($tenantId, $lock), $this->permissionKeys($tenantId, $lock)];
    }
}
