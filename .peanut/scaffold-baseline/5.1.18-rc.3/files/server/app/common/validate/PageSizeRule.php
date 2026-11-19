<?php

declare(strict_types=1);

namespace app\common\validate;

use app\common\support\ExportPageInfo;

/** Shared validation rule for normal pages and explicit unpaged/export reads. */
trait PageSizeRule
{
    private const PAGE_SIZE_MAX = 100;

    protected function pageSizeMax($value, $rule, array $data): bool|string
    {
        $maximum = (int) ($data['page_type'] ?? 1) === 0
            ? ExportPageInfo::MAX_ROWS
            : self::PAGE_SIZE_MAX;

        return (int) $value <= $maximum
            ? true
            : sprintf('每页数量须在1-%d之间', $maximum);
    }
}
