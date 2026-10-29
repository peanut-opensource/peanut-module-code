<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Validation;

use PeanutAdmin\Modules\Member\Model\Member;
use app\common\enum\AccountLogEnum;
use app\common\validate\TenantContextValidate;

class MemberValidate extends TenantContextValidate
{
    protected $rule = [
        'id'       => 'require|integer|gt:0|checkMember',
        'nickname' => 'require|max:50',
        'mobile'   => 'max:20|regex:/^1[3-9]\\d{9}$/',
        'email'    => 'email',
        'sex'      => 'in:0,1,2',
        'status'   => 'require|in:0,1',
        'field'    => 'require|checkField',
        'value'    => 'require',
        'user_id'  => 'require|integer|gt:0|checkMember',
        'action'   => 'require|in:' . AccountLogEnum::INC . ',' . AccountLogEnum::DEC,
        'num'      => 'require|float|gt:0|checkMoney',
        'remark'   => 'max:128',
    ];

    protected $message = [
        'id.require'       => 'id 不能为空',
        'nickname.require' => '昵称不能为空',
        'nickname.max'     => '昵称最多 50 个字符',
        'mobile.max'       => '手机号最多 20 个字符',
        'mobile.regex'     => '手机号码格式错误',
        'email.email'      => '邮箱格式不正确',
        'sex.in'           => '性别值无效',
        'status.require'   => '状态不能为空',
        'status.in'        => '状态值无效',
        'field.require'    => '请选择操作',
        'value.require'    => '请输入内容',
        'user_id.require'  => '请选择用户',
        'action.require'   => '请选择调整类型',
        'action.in'        => '调整类型错误',
        'num.require'      => '请输入调整数量',
        'num.float'        => '调整余额格式错误',
        'num.gt'           => '调整余额必须大于零',
        'remark.max'       => '备注不可超过128个符号',
    ];

    protected $scene = [
        'add'         => ['nickname', 'mobile', 'email', 'sex', 'status'],
        'detail'      => ['id'],
        'setInfo'     => ['id', 'field', 'value'],
        'status'      => ['id', 'status'],
        'adjustMoney' => ['user_id', 'action', 'num', 'remark'],
    ];

    protected function checkMember($value): bool|string
    {
        return Member::where([])->where('id', (int) $value)->findOrEmpty()->isEmpty()
            ? '用户不存在！' : true;
    }

    protected function checkField($value, $rule, array $data): bool|string
    {
        if (!in_array($value, ['account', 'sex', 'mobile', 'real_name'], true)) {
            return '用户信息不允许更新';
        }

        if ($value === 'account') {
            $exists = Member::where([])->where('id', '<>', (int) ($data['id'] ?? 0))
                ->where('account', (string) ($data['value'] ?? ''))
                ->findOrEmpty();
            if (!$exists->isEmpty()) {
                return '账号已被使用';
            }
        }

        if ($value === 'mobile') {
            $mobile = (string) ($data['value'] ?? '');
            if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
                return '手机号码格式错误';
            }
            $exists = Member::where([])->where('id', '<>', (int) ($data['id'] ?? 0))
                ->where('mobile', $mobile)
                ->findOrEmpty();
            if (!$exists->isEmpty()) {
                return '手机号码已存在';
            }
        }

        return true;
    }

    protected function checkMoney($value, $rule, array $data): bool|string
    {
        $member = Member::where([])->where('id', (int) ($data['user_id'] ?? 0))->findOrEmpty();
        if ($member->isEmpty()) {
            return '用户不存在';
        }
        if ((int) ($data['action'] ?? 0) === AccountLogEnum::INC) {
            return true;
        }
        if ((float) $member->user_money - (float) $value < 0) {
            return '用户可用余额仅剩' . $member->user_money;
        }
        return true;
    }

}
