<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Validation;

use think\Validate;

class MemberTagValidate extends Validate
{
    protected $rule = [
        'id'   => 'require|integer|gt:0',
        'name' => 'require|max:50',
    ];

    protected $message = [
        'id.require'   => 'id 不能为空',
        'name.require' => '标签名称不能为空',
        'name.max'     => '标签名称最多 50 个字符',
    ];

    protected $scene = [
        'add'  => ['name'],
        'edit' => ['id', 'name'],
    ];
}
