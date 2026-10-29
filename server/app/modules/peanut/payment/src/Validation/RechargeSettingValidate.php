<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Validation;

use app\common\enum\UserTerminalEnum;
use PeanutAdmin\Modules\Payment\Model\PaymentScene;
use think\Validate;

class RechargeSettingValidate extends Validate
{
    protected $rule = [
        'status' => 'require|in:0,1|checkConfig',
        'min_amount' => 'require|float',
        'max_amount' => 'require|float',
        'scenes' => 'require|array',
    ];

    protected $message = [
        'status.require' => '请选择充值状态',
        'status.in' => '充值状态无效',
        'min_amount.require' => '最低充值金额不能为空',
        'min_amount.float' => '最低充值金额格式无效',
        'max_amount.require' => '最高充值金额不能为空',
        'max_amount.float' => '最高充值金额格式无效',
        'scenes.require' => '支付场景不能为空',
        'scenes.array' => '支付场景格式无效',
    ];

    protected $scene = [
        'save' => ['status', 'min_amount', 'max_amount', 'scenes'],
    ];

    protected function checkConfig(mixed $value, mixed $rule, array $data): bool|string
    {
        if (!in_array((string) $value, ['0', '1'], true)) {
            return '充值状态无效';
        }
        $expectedKeys = ['max_amount', 'min_amount', 'scenes', 'status'];
        $actualKeys = array_keys($data);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            return '充值配置包含非 canonical 字段';
        }

        $minAmount = (float) ($data['min_amount'] ?? 0);
        $maxAmount = (float) ($data['max_amount'] ?? 0);
        if (!is_finite($minAmount) || $minAmount < 0.01 || $minAmount > 99999999.99) {
            return '最低充值金额须在 0.01 至 99999999.99 之间';
        }
        if (!is_finite($maxAmount) || $maxAmount < $minAmount || $maxAmount > 99999999.99) {
            return '最高充值金额须不小于最低充值金额且不超过 99999999.99';
        }

        $scenes = $data['scenes'] ?? null;
        if (!is_array($scenes)) {
            return '支付场景格式无效';
        }

        $matrix = [];
        foreach ($scenes as $scene) {
            if (!is_array($scene)) {
                return '支付场景节点格式无效';
            }
            $sceneKeys = array_keys($scene);
            sort($sceneKeys);
            if ($sceneKeys !== ['is_default', 'pay_way', 'status', 'terminal']) {
                return '支付场景包含非 canonical 字段';
            }

            if (!preg_match('/^[1-6]$/D', (string) $scene['terminal'])
                || !preg_match('/^[23]$/D', (string) $scene['pay_way'])
                || !in_array((string) $scene['status'], ['0', '1'], true)
                || !in_array((string) $scene['is_default'], ['0', '1'], true)) {
                return '支付场景状态或标识无效';
            }
            $terminal = (int) $scene['terminal'];
            $payWay = (int) $scene['pay_way'];
            $status = (int) $scene['status'];
            $isDefault = (int) $scene['is_default'];
            if (!UserTerminalEnum::isValid($terminal) || !PaymentScene::supports($terminal, $payWay)) {
                return '支付终端或渠道无效';
            }
            if ($isDefault === 1 && $status !== PaymentScene::STATUS_ENABLED) {
                return '默认支付渠道必须启用';
            }
            $key = $terminal . ':' . $payWay;
            if (isset($matrix[$key])) {
                return '支付场景不能重复';
            }
            $matrix[$key] = compact('terminal', 'payWay', 'status', 'isDefault');
        }

        $allowed = PaymentScene::allowedPayWays();
        $expectedCount = array_sum(array_map('count', $allowed));
        if (count($matrix) !== $expectedCount) {
            return '必须提交全部支付场景';
        }
        foreach ($allowed as $terminal => $payWays) {
            $enabled = 0;
            $defaults = 0;
            foreach ($payWays as $payWay) {
                $key = $terminal . ':' . $payWay;
                if (!isset($matrix[$key])) {
                    return '必须提交全部支付场景';
                }
                $enabled += $matrix[$key]['status'];
                $defaults += $matrix[$key]['isDefault'];
            }
            if ((int) $value === 1 && ($enabled < 1 || $defaults !== 1)) {
                return UserTerminalEnum::getDesc((int) $terminal) . '必须启用至少一个渠道并设置唯一默认渠道';
            }
        }
        return true;
    }
}
