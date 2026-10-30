<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Validation;

use think\Validate;

class DictDataValidate extends Validate
{
    protected $rule = [
        'id'      => 'require|positiveId',
        'type_id' => 'require|positiveId',
        'name'    => 'require|string|max:100',
        'value'   => 'require|string|max:255',
        'sort'    => 'sortOrder',
        'is_disable' => 'flag',
        'remark' => 'string|max:255',
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
        'add'  => ['type_id', 'name', 'value', 'sort', 'is_disable', 'remark'],
        'edit' => ['id', 'name', 'value', 'sort', 'is_disable', 'remark'],
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

    protected function sortOrder(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 32767]]) !== false;
    }

    public function sceneStatus(): self
    {
        return $this->only(['id', 'is_disable'])->append('is_disable', 'require');
    }
}
