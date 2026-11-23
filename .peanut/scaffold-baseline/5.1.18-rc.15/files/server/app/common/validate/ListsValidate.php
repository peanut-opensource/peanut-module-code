<?php

declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/** 公共列表分页参数校验。 */
class ListsValidate extends Validate
{
    use PageSizeRule;

    protected const PAGE_NUMBER_RULE = 'positiveInteger';
    protected const PAGE_SIZE_RULE = 'positiveInteger|pageSizeMax';
    protected const BOUNDED_PAGE_SIZE_RULE = 'positiveInteger|elt:100';

    protected $rule = [
        'page_no' => self::PAGE_NUMBER_RULE,
        'page_size' => self::PAGE_SIZE_RULE,
    ];

    protected function positiveInteger(mixed $value): bool
    {
        // ThinkPHP's integer rule accepts any numeric value, including decimal and exponent notation.
        return (is_int($value) || is_string($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
