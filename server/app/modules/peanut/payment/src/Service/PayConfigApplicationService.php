<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;
use PeanutAdmin\Modules\Payment\Contract\PaymentChannelGrantCommands;

/**
 * 支付配置 Logic
 *
 * type = pay
 * 字段：微信支付（wx_*）+ 支付宝（ali_*）
 */
class PayConfigApplicationService
{
    protected const CONFIG_TYPE = 'pay';

    public function __construct(
        private readonly PaymentChannelGrantCommands $channelGrants,
        private readonly ExternalChannelBindings $bindings,
    ) {}

    /** @var array<string,mixed> 字段白名单 => 默认值 */
    protected const FIELDS = [
        // 微信支付
        'wx_pay_status'        => 0,   // 0关 1开
        'wx_pay_appid'         => '',
        'wx_pay_mch_id'        => '',
        'wx_pay_secret'        => '',
        'wx_pay_cert_path'     => '',
        'wx_pay_cert_key_path' => '',
        'wx_pay_platform_cert_path' => '',
        // 支付宝
        'ali_pay_status'       => 0,   // 0关 1开
        'ali_pay_app_id'       => '',
        'ali_pay_private_key'  => '',
        'ali_pay_public_key'   => '',
        'ali_pay_seller_id'    => '',
    ];

    public function getConfig(TenantContext $context): array
    {
        $stored = [
            ...$this->bindings->config($context, ExternalProvider::WECHAT_PAYMENT),
            ...$this->bindings->config($context, ExternalProvider::ALIPAY_PAYMENT),
        ];
        $result = [];
        foreach (self::FIELDS as $field => $default) {
            $value = $stored[$field] ?? $default;
            // 开关字段转整型
            if (in_array($field, ['wx_pay_status', 'ali_pay_status'], true)) {
                $value = (int) $value;
            }
            $result[$field] = $value;
        }
        foreach (['wx_pay_secret', 'ali_pay_private_key'] as $field) {
            $configured = trim((string) $result[$field]) !== '';
            $result[$field . '_configured'] = $configured;
            $result[$field] = $configured ? '******' : '';
        }
        return $result;
    }

    public function setConfig(TenantContext $context, array $params): bool
    {
        $stored = [
            ...$this->bindings->config($context, ExternalProvider::WECHAT_PAYMENT),
            ...$this->bindings->config($context, ExternalProvider::ALIPAY_PAYMENT),
        ];
        $data = [];
        foreach (self::FIELDS as $field => $default) {
            $current = (string) ($stored[$field] ?? $default);
            if (!array_key_exists($field, $params)) {
                $data[$field] = $current;
                continue;
            }
            $incoming = trim((string) $params[$field]);
            if ($incoming === '******' && in_array($field, ['wx_pay_secret', 'ali_pay_private_key'], true)) {
                $data[$field] = $current;
            } else {
                $data[$field] = $incoming;
            }
        }
        self::assertUsable($data);
        Db::transaction(function () use ($context, $data): void {
            $this->bindings->update(
                $context,
                ExternalProvider::WECHAT_PAYMENT,
                $data,
                trim((string) $data['wx_pay_appid']) !== '' && trim((string) $data['wx_pay_mch_id']) !== ''
                    ? (string) $data['wx_pay_appid'] . ':' . (string) $data['wx_pay_mch_id'] : '',
            );
            $this->channelGrants->ensureSelfGrant($context, ExternalProvider::WECHAT_PAYMENT);
            $this->bindings->update(
                $context,
                ExternalProvider::ALIPAY_PAYMENT,
                $data,
                trim((string) $data['ali_pay_app_id']) !== '' && trim((string) $data['ali_pay_seller_id']) !== ''
                    ? (string) $data['ali_pay_app_id'] . ':' . (string) $data['ali_pay_seller_id'] : '',
            );
            $this->channelGrants->ensureSelfGrant($context, ExternalProvider::ALIPAY_PAYMENT);
        });
        return true;
    }

    private static function assertUsable(array $data): void
    {
        if ((int) $data['wx_pay_status'] === 1) {
            foreach (['wx_pay_appid', 'wx_pay_mch_id', 'wx_pay_secret', 'wx_pay_cert_path', 'wx_pay_cert_key_path', 'wx_pay_platform_cert_path'] as $field) {
                if (trim((string) $data[$field]) === '') {
                    throw BusinessException::invalid('PAYMENT_WECHAT_CONFIG_INCOMPLETE', '启用微信支付前请完整填写 AppID、商户号、密钥和证书');
                }
            }
            if (strlen((string) $data['wx_pay_secret']) !== 32) {
                throw BusinessException::invalid('PAYMENT_WECHAT_SECRET_INVALID', '微信支付 APIv3 密钥必须为 32 字节');
            }
        }
        if ((int) $data['ali_pay_status'] === 1) {
            foreach (['ali_pay_app_id', 'ali_pay_private_key', 'ali_pay_public_key', 'ali_pay_seller_id'] as $field) {
                if (trim((string) $data[$field]) === '') {
                    throw BusinessException::invalid('PAYMENT_ALIPAY_CONFIG_INCOMPLETE', '启用支付宝前请完整填写应用和密钥配置');
                }
            }
        }
    }
}
