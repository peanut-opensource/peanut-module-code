<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Tenancy\Infrastructure;

use PeanutAdmin\Modules\Identity\Persistence\Model\TenantEntryBinding;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingLookup;
use think\facade\Cache;
use think\facade\Db;

final readonly class ThinkPhpTenantEntryBindingLookup implements TenantEntryBindingLookup
{
    private const CACHE_PREFIX = 'identity:tenant-entry:v2';

    public function binding(string $host, string $clientKey): ?array
    {
        // Writes read their own transaction; only committed entry bindings enter the shared cache.
        $pdo = Db::connect()->getPdo();
        if ($pdo !== false && $pdo->inTransaction()) {
            return $this->readBinding($host, $clientKey);
        }
        $revision = Cache::get(self::CACHE_PREFIX . ':revision');
        if ($revision === null) {
            $revision = bin2hex(random_bytes(16));
            Cache::set(self::CACHE_PREFIX . ':revision', $revision, 0);
        }
        $key = self::CACHE_PREFIX . ':' . $revision . ':' . hash('sha256', $host . "\0" . $clientKey);
        $binding = Cache::get($key);
        if ($binding !== null) {
            return $binding;
        }
        $binding = $this->readBinding($host, $clientKey);
        if ($binding !== null) {
            Cache::set($key, $binding, 300);
        }
        return $binding;
    }

    /** Called by the owning binding/tenant lifecycle after its transaction commits. */
    public static function invalidate(): void
    {
        // In-flight readers may finish filling an old key; new requests select only this revision.
        Cache::set(self::CACHE_PREFIX . ':revision', bin2hex(random_bytes(16)), 0);
    }

    private function readBinding(string $host, string $clientKey): ?array
    {
        try {
            $rows = TenantEntryBinding::alias('binding')
                ->join('tenant tenant', 'tenant.id = binding.tenant_id')
                ->where('binding.host', $host)
                ->where('binding.client_key', $clientKey)
                ->field([
                    'binding.tenant_id',
                    // ThinkORM 以列名为键、结果别名为值；Host 绑定仍须同时校验绑定与租户状态。
                    'binding.status' => 'binding_status',
                    'tenant.code' => 'tenant_code',
                    'tenant.status' => 'tenant_status',
                ])
                ->order('binding.id')
                ->limit(2)
                ->select()
                ->toArray();
        } catch (\Throwable $exception) {
            throw new \DomainException('TENANT_ENTRY_BINDING_UNAVAILABLE', 0, $exception);
        }
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new \DomainException('TENANT_ENTRY_BINDING_UNAVAILABLE');
        }
        $row = $rows[0];
        $tenantId = (int) ($row['tenant_id'] ?? 0);
        $tenantCode = trim((string) ($row['tenant_code'] ?? ''));
        if (($row['binding_status'] ?? null) !== 'active' || ($row['tenant_status'] ?? null) !== 'active' || $tenantId < 1 || $tenantCode === '') {
            throw new \DomainException('TENANT_ENTRY_BINDING_UNAVAILABLE');
        }
        return ['tenant_id' => $tenantId, 'tenant_code' => $tenantCode];
    }

}
