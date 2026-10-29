<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Validation;

use think\Validate;

class OfficialAccountMenuValidate extends Validate
{
    protected $rule = ['menu' => 'require|array|checkMenu'];
    protected $message = [
        'menu.require' => '公众号菜单不能为空',
        'menu.array' => '公众号菜单格式无效',
    ];

    protected function checkMenu(mixed $value): bool|string
    {
        if (!is_array($value)) {
            return '公众号菜单格式无效';
        }
        if (count($value) > 3) {
            return '一级菜单最多 3 个';
        }
        foreach ($value as $item) {
            $error = $this->validateItem($item, true);
            if ($error !== true) {
                return $error;
            }
        }
        return true;
    }

    private function validateItem(mixed $item, bool $topLevel): bool|string
    {
        if (!is_array($item)) {
            return '菜单节点格式无效';
        }
        $name = trim((string) ($item['name'] ?? ''));
        $nameLimit = $topLevel ? 4 : 8;
        if ($name === '' || mb_strlen($name) > $nameLimit) {
            return ($topLevel ? '一级' : '二级') . '菜单名称不能为空且最多 ' . $nameLimit . ' 个字';
        }

        $children = $item['sub_button'] ?? [];
        if ($children !== [] && !is_array($children)) {
            return '子菜单格式无效';
        }
        if (is_array($children) && $children !== []) {
            if (!$topLevel) {
                return '公众号菜单最多两级';
            }
            if (count($children) > 5) {
                return '二级菜单最多 5 个';
            }
            foreach ($children as $child) {
                $error = $this->validateItem($child, false);
                if ($error !== true) {
                    return $error;
                }
            }
            return true;
        }

        $type = (string) ($item['type'] ?? '');
        if (!in_array($type, ['click', 'view', 'miniprogram'], true)) {
            return '公众号菜单类型无效';
        }
        if ($type === 'click' && trim((string) ($item['key'] ?? '')) === '') {
            return '点击菜单必须填写 key';
        }
        if ($type === 'view' && !$this->absoluteHttpUrl((string) ($item['url'] ?? ''))) {
            return '网页菜单必须填写有效的 http/https 地址';
        }
        if ($type === 'miniprogram') {
            if (!$this->absoluteHttpUrl((string) ($item['url'] ?? ''))
                || trim((string) ($item['appid'] ?? '')) === ''
                || trim((string) ($item['pagepath'] ?? '')) === '') {
                return '小程序菜单必须填写备用 URL、AppID 和页面路径';
            }
        }
        return true;
    }

    private function absoluteHttpUrl(string $value): bool
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
