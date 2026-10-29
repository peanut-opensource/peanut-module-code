<?php

declare(strict_types=1);

namespace app\adminapi\validate\decoration;

use think\Validate;

class DecorationPageValidate extends Validate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'type' => 'require|in:1,2,3,4,5',
        'data' => 'require|array',
        'meta' => 'array',
        'limit' => 'integer|between:1,100',
    ];
    protected $message = [
        'id.require' => '装修页面不能为空',
        'id.integer' => '装修页面 ID 无效',
        'type.require' => '装修页面类型不能为空',
        'type.in' => '装修页面类型无效',
        'data.require' => '装修页面数据不能为空',
        'data.array' => '装修页面数据格式无效',
        'meta.array' => '装修页面元数据格式无效',
    ];
    protected $scene = [
        'detail' => ['id'],
        'save' => ['id', 'type', 'data', 'meta'],
        'article' => ['limit'],
    ];
}
