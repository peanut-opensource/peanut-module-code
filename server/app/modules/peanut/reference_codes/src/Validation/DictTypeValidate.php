<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Validation;

use think\Validate;

class DictTypeValidate extends Validate
{
    protected $rule = [
        'id'   => 'require|positiveId',
        'name' => 'require|string|max:100',
        'type' => 'require|string|max:100|alphaDash',
        'is_disable' => 'flag',
        'remark' => 'string|max:255',
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
        'add'  => ['name', 'type', 'is_disable', 'remark'],
        'edit' => ['id', 'name', 'type', 'is_disable', 'remark'],
        'detail' => ['id'],
        'delete' => ['id'],
        'status' => ['id', 'is_disable'],
    ];

    protected function positiveId(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    protected function flag(mixed $value): bool
    {
        return in_array($value, [0, 1, '0', '1'], true);
    }

    public function sceneStatus(): self
    {
        return $this->only(['id', 'is_disable'])->append('is_disable', 'require');
    }
}
