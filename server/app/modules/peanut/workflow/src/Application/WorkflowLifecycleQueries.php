<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use think\facade\Db;

/** Bounded lifecycle projections for the trusted host; no grant of object access. */
final readonly class WorkflowLifecycleQueries
{
    public function __construct(private TenantModuleStateQueries $modules, private \PeanutAdmin\Kernel\Module\ModuleAvailability $availability) {}

    public function assertAvailable(int $tenantId, bool $lock = false): void
    {
        $this->availability->assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($tenantId, 'workflow'), 'peanut.workflow', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $lock);
    }

    public function assertTaskAvailable(int $tenantId, bool $lock = false): void
    {
        try {
            $this->availability->assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($tenantId, 'workflow'), 'peanut.task', new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $lock);
        } catch (\PeanutAdmin\Kernel\Module\ModuleException) {
            throw WorkflowException::providerUnavailable();
        }
    }

    public function assertNewOperations(int $tenantId, bool $lock = false): void
    {
        $this->assertAvailable($tenantId, $lock);
        if (!$this->modules->acceptsNewOperations($tenantId, 'peanut.workflow', $lock)) {
            throw WorkflowException::blocked('Workflow is draining or disabled; new operations are stopped.');
        }
    }

    public static function outstanding(?int $tenantId = null): int
    {
        $eventTable = Db::name('workflow_event')->getTable();
        $columns = array_column(Db::query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$eventTable]), 'COLUMN_NAME');
        if (!in_array('notification_fact_json', $columns, true) || !in_array('notification_outbox_json', $columns, true)) {
            $preflight = (new WorkflowUpgradePreflight(new \PeanutAdmin\Modules\Identity\Contract\WorkflowLegacyOrganizationQuery()))->report();
            if (!$preflight['already_applied']) {
                if ($preflight['may_apply'] && array_sum($preflight['table_counts']) === 0) { return 0; }
                throw WorkflowException::upgradeRequiresReview();
            }
            throw WorkflowException::blocked('Workflow lifecycle requires its pending append-only notification schema migration; run the native upgrade preflight before traffic.');
        }
        $query = Db::name('workflow_instance')->whereIn('status', ['running', 'blocked']);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $instances = (int) $query->count();
        $effects = Db::name('workflow_node_execution')->whereNotNull('task_job_key')->whereNull('effect_receipt_json')->whereIn('status', ['pending', 'active', 'blocked']);
        if ($tenantId !== null) {
            $effects->where('tenant_id', $tenantId);
        }
        $facts = Db::name('workflow_event')->whereNotNull('notification_fact_json');
        if ($tenantId !== null) { $facts->where('tenant_id', $tenantId); }
        $pending = (int) (clone $facts)->whereRaw('(notification_outbox_json IS NULL OR JSON_LENGTH(notification_outbox_json)=0)')->count();
        $sources = (clone $facts)->fieldRaw("DISTINCT JSON_UNQUOTE(JSON_EXTRACT(notification_fact_json, '$.producer_key')) AS producer_key")->limit(101)->select()->toArray();
        if (count($sources) > 100) { throw WorkflowException::blocked('Workflow notification source capacity exceeded; lifecycle review is required.'); }
        $keys = array_column($sources, 'producer_key');
        if ($keys !== [] && class_exists(\PeanutAdmin\Modules\Notification\Contract\NotificationLifecycleQueries::class)) {
            $notification = new \PeanutAdmin\Modules\Notification\Contract\NotificationLifecycleQueries();
            $pending += $tenantId === null ? $notification->pendingAcrossTenants($keys) : $notification->pendingPublications($tenantId, $keys);
        }
        return $instances + (int) $effects->count() + $pending;
    }

    public static function hasRetainedData(): bool
    {
        return Db::name('workflow_definition')->limit(1)->value('id') !== null || Db::name('workflow_instance')->limit(1)->value('id') !== null;
    }
}
