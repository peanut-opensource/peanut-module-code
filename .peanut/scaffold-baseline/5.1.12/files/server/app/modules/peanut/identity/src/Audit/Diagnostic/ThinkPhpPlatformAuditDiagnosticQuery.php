<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Audit\Diagnostic;

use PeanutAdmin\Modules\Identity\Contract\PlatformAuditDiagnosticQuery;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final readonly class ThinkPhpPlatformAuditDiagnosticQuery implements PlatformAuditDiagnosticQuery
{
    public function eventGroupsSince(DateTimeImmutable $since, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('PLATFORM_AUDIT_DIAGNOSTIC_LIMIT_INVALID');
        }

        $rows = Db::name('platform_audit_event')
            ->where('occurred_at', '>=', $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'))
            ->field('event_type,outcome')
            ->fieldRaw('MAX(occurred_at) AS occurred_at,COUNT(*) AS occurrences')
            ->group('event_type,outcome')
            ->order('occurred_at', 'desc')
            ->order('event_type')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(static fn(array $row): array => [
            'event_type' => (string) ($row['event_type'] ?? ''),
            'outcome' => (string) ($row['outcome'] ?? ''),
            'occurrences' => min(1000000, max(1, (int) ($row['occurrences'] ?? 1))),
            'occurred_at' => self::instant((string) ($row['occurred_at'] ?? '')),
        ], $rows);
    }

    private static function instant(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \RuntimeException('PLATFORM_AUDIT_DIAGNOSTIC_TIME_INVALID');
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
