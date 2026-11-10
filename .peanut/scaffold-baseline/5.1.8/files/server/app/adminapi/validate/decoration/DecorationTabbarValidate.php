<?php

declare(strict_types=1);

namespace app\adminapi\validate\decoration;

use think\Validate;

class DecorationTabbarValidate extends Validate
{
    protected $rule = ['style' => 'require|array', 'list' => 'require|array'];
    protected $message = [
        'style.require' => 'Tabbar 样式不能为空',
        'style.array' => 'Tabbar 样式格式无效',
        'list.require' => 'Tabbar 列表不能为空',
        'list.array' => 'Tabbar 列表格式无效',
    ];
}
