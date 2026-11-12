<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use PeanutAdmin\Modules\Identity\Contract\PlatformAuditDiagnosticQuery;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Logs\RuntimeLogProvider;
use PeanutAdmin\Modules\Ops\Domain\Logs\RuntimeLogQuery;
use PeanutAdmin\Modules\Ops\Domain\Logs\StructuredLogBatch;
use PeanutAdmin\Modules\Ops\Domain\Logs\StructuredLogRecord;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;

/** Bounded, metadata-only projection of platform audit events. */
final readonly class PlatformAuditRuntimeLogProvider implements RuntimeLogProvider
{
    public function __construct(
        private DateTimeImmutable $since,
        private PlatformAuditDiagnosticQuery $auditDiagnostics,
    ) {}

    public function sourceKey(): string
    {
        return 'platform.audit';
    }

    public function read(PlatformContext $context, RuntimeLogQuery $query): StructuredLogBatch
    {
        $records = [];
        $rows = $this->auditDiagnostics->eventGroupsSince($this->since, 100);
        foreach ($rows as $row) {
            $severity = match ((string) ($row['outcome'] ?? '')) {
                'success' => 'info',
                'denied' => 'warning',
                'error' => 'error',
                default => 'critical',
            };
            if ($this->rank($severity) < $this->rank($query->minimumSeverity)) {
                continue;
            }
            $records[] = new StructuredLogRecord(
                (string) ($row['event_type'] ?? ''),
                $severity,
                'platform.audit',
                (string) ($row['occurred_at'] ?? ''),
                null,
                min(1000000, max(1, (int) ($row['occurrences'] ?? 1))),
            );
        }
        // Pagination covers only this bounded, redacted summary. A changed
        // summary invalidates the cursor rather than silently skipping rows.
        $digest = hash('sha256', serialize([$query->minimumSeverity, $records]));
        $offset = 0;
        if ($query->cursor !== null) {
            if (preg_match('/^cursor_([a-f0-9]{64})_([0-9]{8})$/D', $query->cursor, $parts) !== 1
                || !hash_equals($digest, $parts[1]) || (int) $parts[2] >= count($records)) {
                throw OpsConsoleException::invalid();
            }
            $offset = (int) $parts[2];
        }
        $next = $offset + $query->pageSize;
        return new StructuredLogBatch(array_slice($records, $offset, $query->pageSize),
            $next < count($records) ? 'cursor_' . $digest . '_' . sprintf('%08d', $next) : null);
    }

    private function rank(string $severity): int
    {
        return match ($severity) {
            'info' => 0,
            'warning' => 1,
            'error' => 2,
            default => 3,
        };
    }
}
