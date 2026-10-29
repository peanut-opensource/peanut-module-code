<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Validation;

use think\Validate;

class WebPageValidate extends Validate
{
    protected $rule = [
        'status'      => 'require|checkStatus',
        'page_status' => 'require|checkPageStatus',
        'page_url'    => 'requireIf:page_status,1|checkPageUrl',
    ];

    protected $message = [
        'status.require'          => '请选择 H5 渠道状态',
        'page_status.require'     => '请选择渠道关闭后的访问方式',
        'page_url.requireIf'      => '请输入渠道关闭后的跳转地址',
    ];

    protected function checkStatus(mixed $value): bool|string
    {
        return $this->isBinaryEnum($value) ?: 'H5 渠道状态值错误';
    }

    protected function checkPageStatus(mixed $value): bool|string
    {
        return $this->isBinaryEnum($value) ?: '渠道关闭后的访问方式值错误';
    }

    protected function checkPageUrl(mixed $value, mixed $rule, array $data): bool|string
    {
        if ((string) ($data['page_status'] ?? '0') !== '1') {
            return true;
        }

        $url = trim((string) $value);
        if ($url === '') {
            return '请输入渠道关闭后的跳转地址';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '跳转地址必须是有效的 http 或 https URL';
        }

        return true;
    }

    private function isBinaryEnum(mixed $value): bool
    {
        return in_array($value, [0, 1, '0', '1'], true);
    }
}
