<?php

declare(strict_types=1);

namespace app\adminapi\validate\config;

use think\Validate;

class WebsiteValidate extends Validate
{
    protected $rule = [
        'config' => 'array|checkCopyright',
        'service_title' => 'require|max:100',
        'service_content' => 'max:200000',
        'privacy_title' => 'require|max:100',
        'privacy_content' => 'max:200000',
        'clarity_code' => 'max:64|checkClarityId',
        'default_avatar' => 'require|max:500',
        'login_way' => 'require|array|checkLoginWay',
        'coerce_mobile' => 'require|in:0,1',
        'login_agreement' => 'require|in:0,1',
        'third_auth' => 'require|in:0,1',
        'wechat_auth' => 'require|in:0,1',
    ];

    protected $message = [
        'config.array' => '备案配置格式错误',
        'default_avatar.require' => '默认头像不能为空',
        'login_way.require' => '至少启用一种登录方式',
    ];

    protected $scene = [
        'copyright' => ['config'],
        'agreement' => ['service_title', 'service_content', 'privacy_title', 'privacy_content'],
        'statistics' => ['clarity_code'],
        'user' => ['default_avatar'],
        'login' => ['login_way', 'coerce_mobile', 'login_agreement', 'third_auth', 'wechat_auth'],
    ];

    protected function checkCopyright(mixed $value): bool|string
    {
        if (count($value) > 20) {
            return '备案配置最多 20 项';
        }
        foreach ($value as $item) {
            if (!is_array($item)
                || trim((string) ($item['key'] ?? '')) === ''
                || trim((string) ($item['value'] ?? '')) === ''
                || mb_strlen((string) $item['key']) > 60
                || mb_strlen((string) $item['value']) > 500) {
                return '备案配置项格式错误';
            }
        }
        return true;
    }

    protected function checkLoginWay(mixed $value): bool|string
    {
        if ($value === []) {
            return '至少启用一种登录方式';
        }
        foreach ($value as $way) {
            if (!in_array((int) $way, [1, 2], true)) {
                return '登录方式无效';
            }
        }
        return true;
    }

    protected function checkClarityId(mixed $value): bool|string
    {
        return preg_match('/^[A-Za-z0-9_-]*$/D', (string) $value) === 1
            ? true
            : '统计标识只能包含字母、数字、下划线和连字符';
    }
}
