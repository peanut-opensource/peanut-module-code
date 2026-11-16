<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceWindow;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceWindowStore;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsAuditEvent;
use think\facade\Db;

/** Application-owned persistence and audit transaction for the Core maintenance contract. */
final readonly class ThinkPhpMaintenanceWindowStore implements MaintenanceWindowStore
{
    public function __construct(
        private AuditContractHost $audit,
    ) {}

    public function current(PlatformContext $context): ?MaintenanceWindow
    {
        $row = Db::name('ops_maintenance_window')->whereIn('state', ['scheduled', 'active'])
            ->field('maintenance_key,state,reason_key,starts_at,ends_at,revision')->order('id', 'desc')->find();

        return $row === null ? null : $this->window($row);
    }

    public function schedule(
        PlatformContext $context,
        MaintenanceWindow $candidate,
        int $expectedRevision,
        string $idempotencyDigest,
        string $requestDigest,
        OpsAuditEvent $audit,
    ): MaintenanceWindow {
        return Db::transaction(function () use (
            $context,
            $candidate,
            $expectedRevision,
            $idempotencyDigest,
            $requestDigest,
            $audit,
        ): MaintenanceWindow {
            $replayed = Db::name('ops_maintenance_window')->where('created_by_operator_id', $context->operatorId)
                ->where('idempotency_digest', $idempotencyDigest)->lock(true)->find();
            if ($replayed !== null) {
                if (!hash_equals((string) $replayed['request_digest'], $requestDigest)) {
                    throw OpsConsoleException::idempotencyConflict();
                }
                return $this->window($replayed);
            }

            $existing = Db::name('ops_maintenance_window')->whereIn('state', ['scheduled', 'active'])
                ->field('revision')->order('id', 'desc')->lock(true)->find();
            if (($existing === null && $expectedRevision !== 0)
                || ($existing !== null && (int) $existing['revision'] !== $expectedRevision)
            ) {
                throw OpsConsoleException::revisionConflict();
            }
            if ($existing !== null) {
                throw OpsConsoleException::operationInProgress();
            }

            Db::name('ops_maintenance_window')->insert([
                'maintenance_key' => $candidate->maintenanceKey,
                'state' => $candidate->state,
                'reason_key' => $candidate->reasonKey,
                'starts_at' => $this->databaseInstant($candidate->startsAt),
                'ends_at' => $this->databaseInstant($candidate->endsAt),
                'revision' => $candidate->revision,
                'idempotency_digest' => $idempotencyDigest,
                'request_digest' => $requestDigest,
                'created_by_operator_id' => $context->operatorId,
                'created_at' => Db::raw('UTC_TIMESTAMP(3)'),
                'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
            $this->audit($context, $audit);

            $created = Db::name('ops_maintenance_window')->where('maintenance_key', $candidate->maintenanceKey)->find();
            if ($created === null) {
                throw OpsConsoleException::internal();
            }
            return $this->window($created);
        });
    }

    public function close(
        PlatformContext $context,
        string $maintenanceKey,
        int $expectedRevision,
        string $idempotencyDigest,
        string $requestDigest,
        OpsAuditEvent $audit,
    ): MaintenanceWindow {
        return Db::transaction(function () use (
            $context,
            $maintenanceKey,
            $expectedRevision,
            $idempotencyDigest,
            $requestDigest,
            $audit,
        ): MaintenanceWindow {
            $current = Db::name('ops_maintenance_window')->where('maintenance_key', $maintenanceKey)->lock(true)->find();
            if ($current === null) {
                throw OpsConsoleException::revisionConflict();
            }
            if ((string) $current['state'] === 'closed'
                && hash_equals((string) $current['idempotency_digest'], $idempotencyDigest)
            ) {
                if (!hash_equals((string) $current['request_digest'], $requestDigest)) {
                    throw OpsConsoleException::idempotencyConflict();
                }
                return $this->window($current);
            }
            if ((int) $current['revision'] !== $expectedRevision || (string) $current['state'] === 'closed') {
                throw OpsConsoleException::revisionConflict();
            }

            $changed = Db::name('ops_maintenance_window')->where('id', $current['id'])
                ->where('revision', $expectedRevision)->update([
                    'state' => 'closed',
                    'revision' => Db::raw('revision + 1'),
                    'idempotency_digest' => $idempotencyDigest,
                    'request_digest' => $requestDigest,
                    'closed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($changed !== 1) {
                throw OpsConsoleException::revisionConflict();
            }
            $this->audit($context, $audit);

            $closed = Db::name('ops_maintenance_window')->where('id', $current['id'])->find();
            if ($closed === null) {
                throw OpsConsoleException::internal();
            }
            return $this->window($closed);
        });
    }

    /** @param array<string, mixed> $row */
    private function window(array $row): MaintenanceWindow
    {
        return new MaintenanceWindow(
            (string) $row['maintenance_key'],
            (string) $row['state'],
            (string) $row['reason_key'],
            $this->publicInstant((string) $row['starts_at']),
            $this->publicInstant((string) $row['ends_at']),
            (int) $row['revision'],
        );
    }

    private function audit(PlatformContext $context, OpsAuditEvent $audit): void
    {
        $this->audit->recordPlatform(
            $audit->eventType,
            $audit->action,
            $context->requestId,
            $context->operatorId,
            $context->accountId,
            $audit->metadata,
            AuditOutcome::Success,
            null,
        );
    }

    private function databaseInstant(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function publicInstant(string $value): string
    {
        $normalized = str_replace(' ', 'T', trim($value));
        if (!str_contains($normalized, '.')) {
            $normalized .= '.000';
        }
        return $normalized . 'Z';
    }
}
