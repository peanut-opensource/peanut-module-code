<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Validation;

use think\Validate;

class FileCateValidate extends Validate
{
    protected $rule = [
        'id'   => 'require|integer|gt:0',
        'pid'  => 'integer|egt:0',
        'type' => 'require|integer',
        'name' => 'require|max:20',
    ];

    protected $message = [
        'id.require'   => 'id 不能为空',
        'type.require' => '文件类型不能为空',
        'name.require' => '分类名称不能为空',
        'name.max'     => '分类名称最多 64 个字符',
    ];

    protected $scene = [
        'add'  => ['pid', 'type', 'name'],
        'edit' => ['id', 'name'],
    ];
}
