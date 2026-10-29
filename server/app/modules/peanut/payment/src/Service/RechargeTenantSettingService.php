<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

use PeanutAdmin\Modules\Payment\Model\PaymentScene;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use PeanutAdmin\Modules\Payment\Contract\PaymentChannelGrantCommands;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final class RechargeTenantSettingService
{
    public const NAMESPACE = 'finance.recharge';

    public function __construct(
        private readonly TenantSettingsQuery $settingsQuery,
        private readonly TenantSettingsCommands $settingsCommands,
        private readonly PaymentChannelGrantCommands $channelGrants,
    ) {}

    public function config(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
    ): array {
        return $this->settingsQuery->get($context, self::NAMESPACE, self::defaults())->document;
    }

    public function replace(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        array $config,
    ): void {
        $this->settingsCommands->replace($context, self::NAMESPACE, $config);
    }

    public function enabledScenes(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $terminal,
    ): array {
        $config = $this->config($context);
        $scenes = array_filter(
            is_array($config['scenes'] ?? null) ? $config['scenes'] : [],
            static fn(array $scene): bool => (int) ($scene['terminal'] ?? 0) === $terminal
                && (int) ($scene['status'] ?? 0) === PaymentScene::STATUS_ENABLED,
        );
        usort(
            $scenes,
            static fn(array $left, array $right): int =>
            [(int) ($right['is_default'] ?? 0), (int) ($left['pay_way'] ?? 0)]
            <=> [(int) ($left['is_default'] ?? 0), (int) ($right['pay_way'] ?? 0)],
        );
        return array_values($scenes);
    }

    public function scene(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $terminal,
        int $payWay,
    ): ?array {
        foreach ($this->enabledScenes($context, $terminal) as $scene) {
            if ((int) $scene['pay_way'] === $payWay) {
                return $scene;
            }
        }
        return null;
    }

    public function defaultScene(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $terminal,
    ): ?array {
        foreach ($this->enabledScenes($context, $terminal) as $scene) {
            if ((int) ($scene['is_default'] ?? 0) === 1) {
                return $scene;
            }
        }
        return null;
    }

    public function channelConfigured(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $payWay,
    ): bool {
        return $this->channelGrants->channelConfigured($context, $payWay);
    }

    private static function defaults(): array
    {
        $scenes = PaymentScene::field('terminal,pay_way,status,is_default')
            ->order('terminal', 'asc')->order('pay_way', 'asc')->select()->toArray();
        return [
            'status' => 0,
            'min_amount' => '0.01',
            'max_amount' => '99999.00',
            'scenes' => array_map(static fn(array $scene): array => [
                'terminal' => (int) $scene['terminal'],
                'pay_way' => (int) $scene['pay_way'],
                'status' => (int) $scene['status'],
                'is_default' => (int) $scene['is_default'],
            ], $scenes),
        ];
    }
}
