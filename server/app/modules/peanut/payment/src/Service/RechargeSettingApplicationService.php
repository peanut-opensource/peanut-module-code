<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

use PeanutAdmin\Modules\Payment\Model\PaymentScene;
use app\common\enum\UserTerminalEnum;
use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;

class RechargeSettingApplicationService
{
    public function __construct(private readonly RechargeTenantSettingService $settings) {}

    public function getConfig(TenantContext $context): array
    {
        $config = $this->settings->config($context);
        $config['status'] = (int) $config['status'];
        $config['min_amount'] = self::amount($config['min_amount']);
        $config['max_amount'] = self::amount($config['max_amount']);
        return $config;
    }

    /**
     * 返回指定终端当前可用于充值的渠道，默认渠道排在首位。
     *
     * @return array<int, array{pay_way:int,is_default:int}>
     */
    public function availablePayWays(TenantContext $context, int $terminal): array
    {
        if (!UserTerminalEnum::isValid($terminal)
            || (int) $this->settings->config($context)['status'] !== 1) {
            return [];
        }

        return array_values(array_filter(
            $this->settings->enabledScenes($context, $terminal),
            fn(array $scene): bool => $this->settings->channelConfigured(
                $context,
                (int) $scene['pay_way'],
            ),
        ));
    }

    public function save(TenantContext $context, array $params): bool
    {
        foreach ($params['scenes'] as $scene) {
            if ((int) $scene['status'] === 1
                && !$this->settings->channelConfigured($context, (int) $scene['pay_way'])) {
                throw BusinessException::conflict('RECHARGE_CHANNEL_DISABLED', PaymentScene::getPayWayDesc((int) $scene['pay_way']) . '未启用，不能用于充值场景');
            }
        }
        $this->settings->replace($context, [
            'status' => (int) $params['status'],
            'min_amount' => self::amount($params['min_amount']),
            'max_amount' => self::amount($params['max_amount']),
            'scenes' => array_map(static fn(array $scene): array => [
                'terminal' => (int) $scene['terminal'],
                'pay_way' => (int) $scene['pay_way'],
                'status' => (int) $scene['status'],
                'is_default' => (int) $scene['is_default'],
            ], $params['scenes']),
        ]);
        return true;
    }

    private static function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
