<?php

declare(strict_types=1);

namespace app\adminapi\validate\auth;

use think\Validate;

class MenuValidate extends Validate
{
    protected $rule = [
        'menu_key'   => 'require|max:160|regex:/^[a-zA-Z0-9_.-]+$/',
        'name'       => 'require|max:50',
        'type'       => 'require|in:M,C,A',
        'parent_key' => 'max:160|regex:/^[a-zA-Z0-9_.-]+$/',
        'icon'       => 'max:100',
        'sort'       => 'integer',
        'perms'      => 'max:100',
        'paths'      => 'max:200',
        'component'  => 'max:200',
        'is_cache'   => 'in:0,1',
        'is_show'    => 'in:0,1',
        'is_disable' => 'in:0,1',
    ];

    protected $message = [
        'menu_key.require' => '菜单标识不能为空',
        'name.require' => '菜单名称不能为空',
        'name.max'     => '菜单名称最多 50 个字符',
        'type.require' => '菜单类型不能为空',
        'type.in'      => '菜单类型只能是 M（目录）/C（菜单）/A（按钮）',
    ];

    protected $scene = [
        'add'    => ['name', 'type', 'parent_key', 'icon', 'sort', 'perms', 'paths', 'component', 'is_cache', 'is_show', 'is_disable'],
        'edit'   => ['menu_key', 'name', 'type', 'parent_key', 'icon', 'sort', 'perms', 'paths', 'component', 'is_cache', 'is_show', 'is_disable'],
        'detail' => ['menu_key'],
        'delete' => ['menu_key'],
        'status' => ['menu_key', 'is_disable' => 'require|in:0,1'],
    ];
}
