<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use PeanutAdmin\Modules\Task\Contract\TaskDiagnosticQuery;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final readonly class TaskDiagnosticService implements TaskDiagnosticQuery
{
    public function failedGroupsSince(DateTimeImmutable $since, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('TASK_DIAGNOSTIC_LIMIT_INVALID');
        }

        $rows = Db::name('task_job')
            ->where('status', 'dead')
            ->where('updated_at', '>=', $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'))
            ->field('task_type,status')
            ->fieldRaw("COALESCE(last_error_code, 'TASK_ERROR_UNSPECIFIED') AS error_code,COUNT(*) AS occurrences,MAX(updated_at) AS last_seen_at")
            ->group('task_type,status,last_error_code')
            ->order('last_seen_at', 'desc')
            ->order('task_type')
            ->limit($limit)
            ->select()
            ->toArray();

        $groups = [];
        foreach ($rows as $row) {
            $taskType = (string) ($row['task_type'] ?? '');
            $errorCode = (string) ($row['error_code'] ?? '');
            $groups[] = [
                'task_type' => preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $taskType) === 1
                    ? $taskType
                    : 'task.unknown',
                'status' => 'dead',
                'error_code' => preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D', $errorCode) === 1
                    ? $errorCode
                    : 'TASK_ERROR_REDACTED',
                'occurrences' => min(1000000, max(1, (int) ($row['occurrences'] ?? 1))),
                'last_seen_at' => $this->instant((string) ($row['last_seen_at'] ?? '')),
            ];
        }

        return $groups;
    }

    private function instant(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \RuntimeException('TASK_DIAGNOSTIC_TIME_INVALID');
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
