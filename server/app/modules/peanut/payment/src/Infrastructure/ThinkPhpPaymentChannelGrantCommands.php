<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Infrastructure;

use PeanutAdmin\Modules\Payment\Model\PaymentScene;
use PeanutAdmin\Modules\Payment\Model\PaymentTenantChannelGrant;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use app\common\tenancy\PlatformTenantDataGateway;
use PeanutAdmin\Modules\Payment\Contract\PaymentChannelGrantCommands;
use think\facade\Db;

final class ThinkPhpPaymentChannelGrantCommands implements PaymentChannelGrantCommands
{
    public function __construct(
        private readonly ExternalTenantResolutionService $bindings,
        private readonly PlatformTenantDataGateway $tenantData,
    ) {}

    public function providerForPayWay(int $payWay): string
    {
        return match ($payWay) {
            PaymentScene::PAY_WAY_WECHAT => ExternalProvider::WECHAT_PAYMENT,
            PaymentScene::PAY_WAY_ALIPAY => ExternalProvider::ALIPAY_PAYMENT,
            default => throw new \runtimeException('支付渠道不受支持'),
        };
    }

    public function channelForPayWay(int $payWay): string
    {
        return match ($payWay) {
            PaymentScene::PAY_WAY_WECHAT => 'wechat',
            PaymentScene::PAY_WAY_ALIPAY => 'alipay',
            default => throw new \runtimeException('支付渠道不受支持'),
        };
    }

    /** @return array<string,mixed> */
    public function activeGrantForTenant(object $context, string $provider, bool $lock = false): array
    {
        return $this->findActiveGrant($context, $provider, $lock)
            ?? throw new \runtimeException('支付渠道未授权或已撤销');
    }

    /** @return array<string,mixed>|null */
    private function findActiveGrant(object $context, string $provider, bool $lock = false): ?array
    {
        $tenantId = ExternalTenantContext::tenantId($context);
        $query = PaymentTenantChannelGrant::where('provider', $provider)
            ->where('status', 1)
            ->whereNull('revoked_at')
            ->field('id,tenant_id,provider,external_binding_id,merchant_account_ref,merchant_group_ref')
            ->limit(2);
        if ($lock) {
            $query->lock(true);
        }
        $rows = $query->select()->toArray();
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new \runtimeException('支付渠道授权状态冲突');
        }
        $row = $rows[0];
        $binding = $this->bindingForTenant(
            $provider,
            $tenantId,
            (int) $row['external_binding_id'],
            $lock,
        );
        if ($binding === null || !$binding->active || !$binding->tenantActive) {
            return null;
        }
        $row['callback_key'] = $binding->callbackKey;
        $row['identity_hash'] = $binding->identityHash;
        $row['identity_hint'] = $binding->identityHint;
        $row['config'] = $binding->config;
        return $row;
    }

    public function channelConfigured(object $context, int $payWay): bool
    {
        $grant = $this->findActiveGrant($context, $this->providerForPayWay($payWay));
        if ($grant === null) {
            return false;
        }
        return $payWay === PaymentScene::PAY_WAY_WECHAT
            ? (int) ($grant['config']['wx_pay_status'] ?? 0) === 1
            : (int) ($grant['config']['ali_pay_status'] ?? 0) === 1;
    }

    public function ensureSelfGrant(object $context, string $provider): void
    {
        $tenantId = ExternalTenantContext::tenantId($context);
        $binding = $this->bindingForTenant($provider, $tenantId);
        if ($binding === null) {
            return;
        }
        $this->grantTenantChannel(
            $tenantId,
            $provider,
            $binding->id,
            $binding->identityHash,
            '',
        );
    }

    public function grantTenantChannel(
        int $tenantId,
        string $provider,
        int $externalBindingId,
        string $merchantAccountRef = '',
        string $merchantGroupRef = '',
    ): int {
        $provider = trim($provider);
        if ($tenantId < 1 || $provider === '' || $externalBindingId < 1) {
            throw new \runtimeException('支付渠道授权参数无效');
        }
        return (int) Db::transaction(function () use (
            $tenantId,
            $provider,
            $externalBindingId,
            $merchantAccountRef,
            $merchantGroupRef
        ): int {
            $this->grantQuery('grant.lock')
                ->where('tenant_id', $tenantId)
                ->where('provider', $provider)
                ->lock(true)
                ->select();
            $binding = $this->bindingForTenant($provider, $tenantId, $externalBindingId, true);
            if ($binding === null || !$binding->tenantActive) {
                throw new \runtimeException('支付渠道账户不存在或不属于当前租户');
            }
            $now = time();
            $this->grantQuery('grant.revoke-others')
                ->where('tenant_id', $tenantId)
                ->where('provider', $provider)
                ->where('external_binding_id', '<>', $externalBindingId)
                ->where('status', 1)
                ->whereNull('revoked_at')
                ->update([
                    'status' => 0,
                    'revoked_at' => $now,
                    'update_time' => $now,
                ]);
            $existing = $this->grantQuery('grant.find')
                ->where('tenant_id', $tenantId)
                ->where('provider', $provider)
                ->where('external_binding_id', $externalBindingId)
                ->find();
            $data = [
                'merchant_account_ref' => $merchantAccountRef !== ''
                    ? $merchantAccountRef
                    : $binding->identityHash,
                'merchant_group_ref' => $merchantGroupRef,
                'status' => 1,
                'revoked_at' => null,
                'update_time' => $now,
            ];
            if ($existing instanceof PaymentTenantChannelGrant) {
                $existingId = (int) $existing['id'];
                $this->grantQuery('grant.update')
                    ->where('id', $existingId)
                    ->update($data);
                return $existingId;
            }
            return (int) $this->grantQuery('grant.insert')->insertGetId([
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'external_binding_id' => $externalBindingId,
                ...$data,
                'create_time' => $now,
            ]);
        });
    }

    public function revokeTenantChannel(int $tenantId, string $provider, int $externalBindingId): void
    {
        if ($tenantId < 1 || trim($provider) === '' || $externalBindingId < 1) {
            throw new \runtimeException('支付渠道授权参数无效');
        }
        $this->grantQuery('revoke')
            ->where('tenant_id', $tenantId)
            ->where('provider', trim($provider))
            ->where('external_binding_id', $externalBindingId)
            ->update([
                'status' => 0,
                'revoked_at' => time(),
                'update_time' => time(),
            ]);
    }

    private function grantQuery(string $operation): \think\db\BaseQuery
    {
        return $this->tenantData->query(
            PaymentTenantChannelGrant::class,
            'payment.channel-grant',
            $operation,
        );
    }

    private function bindingForTenant(
        string $provider,
        int $tenantId,
        ?int $bindingId = null,
        bool $lock = false,
    ): ?ExternalTenantBinding {
        return $this->bindings->bindingForGrant($tenantId, $provider, $bindingId, $lock);
    }
}
