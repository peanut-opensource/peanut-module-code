<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

use think\facade\Db;

/** Read-only counts for the already authorized tenant lifecycle and installation/maintenance hosts. Grants no access. */
final class NotificationLifecycleQueries
{
    public function pendingPublications(int $tenantId, array $exactProducerKeys): int
    {
        if ($tenantId < 1) { throw new \InvalidArgumentException('NOTIFICATION_LIFECYCLE_TENANT_REQUIRED'); }
        return $this->count($tenantId, $exactProducerKeys);
    }

    /** Explicit installation/maintenance boundary only; ordinary tenant callers use pendingPublications. */
    public function pendingAcrossTenants(array $exactProducerKeys): int { return $this->count(null, $exactProducerKeys); }

    private function count(?int $tenantId, array $keys): int
    {
        if ($keys === []) { return 0; }
        if (!array_is_list($keys) || count($keys) > 100) { throw new \InvalidArgumentException('NOTIFICATION_LIFECYCLE_KEYS_INVALID'); }
        foreach ($keys as $key) { if (!is_string($key) || $key === '' || strlen($key) > 160) { throw new \InvalidArgumentException('NOTIFICATION_LIFECYCLE_KEYS_INVALID'); } }
        $table = Db::name('notification_message')->getTable();
        $columns = array_column(Db::query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]), 'COLUMN_NAME');
        if (!in_array('producer_key', $columns, true)) { throw new \RuntimeException('NOTIFICATION_PRODUCER_SCHEMA_UPGRADE_REQUIRED'); }
        $query = Db::name('notification_outbox')->alias('o')->join('notification_message m', 'm.id=o.message_id AND m.tenant_id=o.tenant_id')
            ->whereIn('m.producer_key', $keys)->whereIn('o.status', ['pending', 'queued', 'processing', 'retryable']);
        if ($tenantId !== null) { $query->where('o.tenant_id', $tenantId)->where('m.tenant_id', $tenantId); }
        return (int) $query->count('o.id');
    }
}
