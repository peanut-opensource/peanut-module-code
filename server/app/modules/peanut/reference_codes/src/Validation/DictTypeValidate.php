<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Validation;

use think\Validate;

class DictTypeValidate extends Validate
{
    protected $rule = [
        'id'   => 'require|integer|gt:0',
        'name' => 'require|max:100',
        'type' => 'require|max:100|alphaDash',
        'is_disable' => 'require|in:0,1',
    ];

    protected $message = [
        'id.require'   => 'id 不能为空',
        'name.require' => '字典名称不能为空',
        'name.max'     => '字典名称最多 100 个字符',
        'type.require' => '字典类型标识不能为空',
        'type.max'     => '字典类型标识最多 100 个字符',
        'type.alphaDash' => '字典类型标识只能是字母、数字、下划线和短横线',
    ];

    protected $scene = [
        'add'  => ['name', 'type'],
        'edit' => ['id', 'name', 'type'],
        'detail' => ['id'],
        'delete' => ['id'],
        'status' => ['id', 'is_disable'],
    ];
}
