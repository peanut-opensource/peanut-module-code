<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Validation;

use think\Validate;

class MemberTagValidate extends Validate
{
    protected $rule = [
        'id'     => 'require|positiveId',
        'name'   => 'require|string|max:50',
        'remark' => 'string|max:255',
    ];

    protected $message = [
        'id.require'    => 'id 不能为空',
        'id.positiveId' => 'id 必须是正整数',
        'name.require'  => '标签名称不能为空',
        'name.string'   => '标签名称必须是字符串',
        'name.max'      => '标签名称最多 50 个字符',
        'remark.string' => '备注必须是字符串',
        'remark.max'    => '备注最多 255 个字符',
    ];

    protected $scene = [
        'add'    => ['name', 'remark'],
        'edit'   => ['id', 'name', 'remark'],
        'delete' => ['id'],
    ];

    protected function positiveId(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
