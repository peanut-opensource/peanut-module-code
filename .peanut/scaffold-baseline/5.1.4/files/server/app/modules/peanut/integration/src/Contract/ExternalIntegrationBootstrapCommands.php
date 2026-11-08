<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use think\facade\Db;

/** 仅供受信平台初始化创建停用绑定；不覆盖配置、不激活渠道、不接受任意提供方。 */
final readonly class ExternalIntegrationBootstrapCommands
{
    public const DEFAULT_PROVIDERS = [
        'payment.wechat',
        'payment.alipay',
        'wechat.official-account',
        'oauth.wechat.oa',
        'oauth.wechat.mini-program',
        'oauth.wechat.open-pc',
    ];

    public function __construct(
        private ExternalChannelBindingStore $bindings,
        private CurrentExecutionContext $execution,
        private AdminDirectoryQuery $tenants,
    ) {}

    public function ensureUnconfiguredBinding(int $tenantId, string $tenantCode, string $provider): void
    {
        if ($tenantId < 1 || trim($tenantCode) === '' || !in_array($provider, self::DEFAULT_PROVIDERS, true)) {
            throw new \DomainException('INTEGRATION_BOOTSTRAP_INPUT_INVALID');
        }
        $system = $this->execution->system();
        if ($system->tenantId !== $tenantId || $system->actorKey !== 'platform.tenant-bootstrap'
            || $system->operation !== 'integration.bootstrap-bindings') {
            throw new \DomainException('INTEGRATION_BOOTSTRAP_CONTEXT_INVALID');
        }
        Db::transaction(function () use ($tenantId, $tenantCode, $provider): void {
            if ($this->tenants->bootstrapTenantCode($tenantId) !== $tenantCode) {
                throw new \DomainException('INTEGRATION_BOOTSTRAP_TENANT_INVALID');
            }
            $this->bindings->ensureUnconfiguredBinding($tenantId, $tenantCode, $provider);
        });
    }
}
