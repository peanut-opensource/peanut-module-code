<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

/** Completed opinions are distinct from pending/cancelled responsibility seats. */
final class WorkflowAggregation
{
    public static function result(string $mode, ?string $policy, array $items): ?string
    {
        if (!$items) {
            throw WorkflowException::unresolved();
        }
        if ($policy === 'first_response') {
            throw WorkflowException::unsupported('workflow.first_response');
        }
        $votes = array_column(array_filter($items, static fn(array $i): bool => $i['status'] === 'completed'), 'decision');
        $pending = array_filter($items, static fn(array $i): bool => $i['status'] === 'pending');
        if ($mode !== 'multi') {
            return $votes[0] ?? null;
        }
        return match ($policy) {
            'all_approve' => in_array('reject', $votes, true) ? 'reject' : (!$pending && count($votes) === count($items) && count(array_filter($votes, static fn(string $v): bool => $v === 'approve')) === count($items) ? 'approve' : null),
            'any_approve' => in_array('approve', $votes, true) ? 'approve' : (!$pending && count($votes) === count($items) && count(array_filter($votes, static fn(string $v): bool => $v === 'reject')) === count($items) ? 'reject' : null),
            default => throw WorkflowException::unsupported((string) $policy),
        };
    }
}
