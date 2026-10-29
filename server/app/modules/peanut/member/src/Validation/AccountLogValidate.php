<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Validation;

use app\common\validate\PageSizeRule;
use think\Validate;

class AccountLogValidate extends Validate
{
    use PageSizeRule;

    protected $rule = [
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|gt:0|pageSizeMax',
        'page_type' => 'in:0,1',
        'change_type' => 'integer',
        'start_time' => 'date',
        'end_time' => 'date|gt:start_time',
        'export' => 'in:1,2',
    ];

    protected $message = [
        'end_time.gt' => '搜索的时间范围不正确',
    ];

    public function sceneLists(): self
    {
        return $this->only([
            'page_no', 'page_size', 'page_type', 'change_type',
            'start_time', 'end_time', 'export',
        ]);
    }

}
