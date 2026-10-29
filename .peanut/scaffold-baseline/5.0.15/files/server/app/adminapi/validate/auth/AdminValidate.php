<?php

declare(strict_types=1);

namespace app\adminapi\validate\auth;

use app\common\validate\PageSizeRule;
use app\common\validate\PasswordRule;
use think\Validate;

class AdminValidate extends Validate
{
    use PageSizeRule;
    use PasswordRule;

    protected $rule = [
        'id' => 'require|integer|gt:0',
        'account' => 'require|email|max:255',
        'name' => 'require|length:1,120',
        'avatar' => 'max:512',
        'password' => 'passwordPolicy',
        'password_confirm' => 'requireWith:password|checkPasswordConfirm',
        'role_id' => 'array',
        'dept_id' => 'array',
        'jobs_id' => 'array',
        'disable' => 'require|in:0,1',
        'multipoint_login' => 'require|in:0,1',
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|gt:0|pageSizeMax',
        'page_type' => 'in:0,1',
        'page_start' => 'integer|gt:0',
        'page_end' => 'integer|gt:0|checkPageEnd',
        'field' => 'in:id,create_time',
        'order_by' => 'in:asc,desc',
        'export' => 'in:1,2',
        'file_name' => 'max:100',
    ];

    protected $message = [
        'id.require' => '管理员id不能为空',
        'id.integer' => '管理员id格式错误',
        'id.gt' => '管理员id格式错误',
        'account.require' => '账号不能为空',
        'account.length' => '账号长度须在1-32位字符',
        'name.require' => '名称不能为空',
        'name.length' => '名称须在1-16位字符',
        'avatar.max' => '头像地址不能超过255个字符',
        'password.require' => '密码不能为空',
        'password_confirm.requireWith' => '确认密码不能为空',
        'role_id.require' => '请选择角色',
        'role_id.array' => '角色格式错误',
        'dept_id.array' => '部门格式错误',
        'jobs_id.array' => '岗位格式错误',
        'disable.require' => '请选择状态',
        'disable.in' => '状态值错误',
        'multipoint_login.require' => '请选择是否支持多处登录',
        'multipoint_login.in' => '多处登录状态值错误',
        'page_no.integer' => '页码必须为整数',
        'page_no.gt' => '页码必须大于0',
        'page_size.integer' => '每页数量必须为整数',
        'page_type.in' => '导出范围类型错误',
        'page_start.integer' => '导出起始页必须为整数',
        'page_start.gt' => '导出起始页必须大于0',
        'page_end.integer' => '导出结束页必须为整数',
        'page_end.gt' => '导出结束页必须大于0',
        'field.in' => '排序字段错误',
        'order_by.in' => '排序方式错误',
        'export.in' => '导出类型错误',
        'file_name.max' => '导出文件名不能超过100个字符',
    ];

    protected $scene = [
        'lists' => [
            'account', 'name', 'role_id', 'page_no', 'page_size', 'page_type',
            'page_start', 'page_end', 'field', 'order_by', 'export', 'file_name',
        ],
        'detail' => ['id'],
        'delete' => ['id'],
        'status' => ['id', 'disable'],
        'edit' => [
            'id', 'account', 'name', 'avatar', 'password', 'password_confirm',
            'role_id', 'dept_id', 'jobs_id', 'disable', 'multipoint_login',
        ],
    ];

    public function sceneLists(): self
    {
        return $this->only($this->scene['lists'])
            ->remove('account', 'require')
            ->remove('name', 'require')
            ->remove('role_id', 'array')
            ->append('role_id', 'integer|gt:0');
    }

    public function sceneAdd(): self
    {
        return $this->only([
            'account', 'name', 'avatar', 'password', 'password_confirm',
            'role_id', 'dept_id', 'jobs_id', 'disable', 'multipoint_login',
        ])->append('password', 'require')
            ->append('role_id', 'require');
    }

    public function checkPasswordConfirm($value, $rule, array $data): bool|string
    {
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            return true;
        }
        if (!array_key_exists('password_confirm', $data) || (string) $value === '') {
            return '确认密码不能为空';
        }
        return hash_equals($password, (string) $value) ? true : '两次输入的密码不一致';
    }

    public function checkPageEnd($value, $rule, array $data): bool|string
    {
        $start = (int) ($data['page_start'] ?? 1);
        return (int) $value < $start ? '导出范围设置不正确，请重新选择' : true;
    }

}
