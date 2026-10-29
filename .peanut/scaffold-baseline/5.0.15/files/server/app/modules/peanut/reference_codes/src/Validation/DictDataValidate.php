<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Validation;

use think\Validate;

class DictDataValidate extends Validate
{
    protected $rule = [
        'id'      => 'require|integer|gt:0',
        'type_id' => 'require|integer|gt:0',
        'name'    => 'require|max:100',
        'value'   => 'require|max:255',
        'sort'    => 'integer|egt:0',
        'is_disable' => 'require|in:0,1',
    ];

    protected $message = [
        'id.require'      => 'id 不能为空',
        'type_id.require' => '字典类型不能为空',
        'name.require'    => '数据名称不能为空',
        'name.max'        => '数据名称最多 100 个字符',
        'value.require'   => '数据值不能为空',
        'value.max'       => '数据值最多 255 个字符',
    ];

    protected $scene = [
        'add'  => ['type_id', 'name', 'value', 'sort'],
        'edit' => ['id', 'name', 'value', 'sort'],
        'detail' => ['id'],
        'delete' => ['id'],
        'status' => ['id', 'is_disable'],
    ];
}
