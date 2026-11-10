<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Audit\Diagnostic;

use PeanutAdmin\Modules\Identity\Contract\TenantAuditDiagnosticQuery;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final readonly class ThinkPhpTenantAuditDiagnosticQuery implements TenantAuditDiagnosticQuery
{
    public function recentOperationEvidence(DateTimeImmutable $since, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('TENANT_AUDIT_DIAGNOSTIC_LIMIT_INVALID');
        }
        $rows = Db::name('tenant_audit_event')->where('event_type', 'admin.operation')
            ->where('occurred_at', '>=', $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'))
            ->field('tenant_id,request_id,operation_id,action,outcome,reason_code,target_resource_id,occurred_at')
            ->order('occurred_at', 'desc')->order('id', 'desc')->limit($limit)->select()->toArray();

        return array_map(static fn(array $row): array => [
            'tenant_id' => (int) $row['tenant_id'],
            'request_id' => (string) $row['request_id'],
            'operation_id' => $row['operation_id'] === null ? null : (string) $row['operation_id'],
            'operation' => (string) $row['action'],
            'outcome' => (string) $row['outcome'],
            'reason_code' => $row['reason_code'] === null ? null : (string) $row['reason_code'],
            'route' => (string) $row['target_resource_id'],
            'occurred_at' => self::instant((string) $row['occurred_at']),
        ], $rows);
    }

    private static function instant(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \RuntimeException('TENANT_AUDIT_DIAGNOSTIC_TIME_INVALID');
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
