<?php

declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/** 公共列表分页参数校验。 */
class ListsValidate extends Validate
{
    use PageSizeRule;

    protected $rule = [
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|gt:0|pageSizeMax',
    ];

}
