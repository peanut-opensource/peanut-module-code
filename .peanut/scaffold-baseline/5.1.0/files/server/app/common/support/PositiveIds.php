<?php

declare(strict_types=1);

namespace app\common\support;

use InvalidArgumentException;

/**
 * Explicit policies for normalizing positive integer ID collections.
 *
 * Callers must select the invalid-value and empty-input semantics they need;
 * this prevents a rejecting bulk operation from accidentally inheriting a
 * filtering policy used by a selector.
 */
final class PositiveIds
{
    public const REJECT_INVALID = 'reject-invalid';
    public const FILTER_INVALID = 'filter-invalid';
    public const REQUIRE_NON_EMPTY = 'require-non-empty';
    public const SORT = 'sort';

    /**
     * @param list<mixed>|array<mixed> $ids
     * @param list<string> $strategies
     * @return list<int>
     */
    public static function normalize(
        array $ids,
        array $strategies = [self::FILTER_INVALID],
        string $invalidMessage = 'ID 无效',
        string $emptyMessage = 'ID 集合不能为空',
    ): array {
        $strategies = array_values(array_unique($strategies));
        $rejectInvalid = in_array(self::REJECT_INVALID, $strategies, true);
        $filterInvalid = in_array(self::FILTER_INVALID, $strategies, true);
        if ($rejectInvalid === $filterInvalid) {
            throw new InvalidArgumentException(
                'Exactly one of reject-invalid or filter-invalid must be selected.',
            );
        }

        $knownStrategies = [
            self::REJECT_INVALID,
            self::FILTER_INVALID,
            self::REQUIRE_NON_EMPTY,
            self::SORT,
        ];
        foreach ($strategies as $strategy) {
            if (!in_array($strategy, $knownStrategies, true)) {
                throw new InvalidArgumentException('Unknown positive ID strategy: ' . $strategy);
            }
        }

        $normalized = [];
        foreach (array_map('intval', $ids) as $id) {
            if ($id <= 0) {
                if ($rejectInvalid) {
                    throw new InvalidArgumentException($invalidMessage);
                }
                continue;
            }
            $normalized[] = $id;
        }

        $normalized = array_values(array_unique($normalized));
        if (in_array(self::REQUIRE_NON_EMPTY, $strategies, true) && $normalized === []) {
            throw new InvalidArgumentException($emptyMessage);
        }
        if (in_array(self::SORT, $strategies, true)) {
            sort($normalized, SORT_NUMERIC);
        }

        return $normalized;
    }
}
