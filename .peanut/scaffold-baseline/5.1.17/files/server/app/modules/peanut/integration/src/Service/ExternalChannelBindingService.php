<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Service;

use PeanutAdmin\Modules\Integration\Contract\ExternalTenantContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindingStore;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;

final class ExternalChannelBindingService implements ExternalChannelBindings
{
    public function __construct(
        private readonly ExternalTenantBindingRepository $repository,
        private readonly ExternalTenantResolver $resolver,
        private readonly ExternalChannelBindingStore $store,
    ) {}

    public function config(TenantContext $context, string $provider): array
    {
        $binding = $this->optionalBinding($context, $provider);
        return $binding?->config ?? [];
    }

    public function callbackKey(TenantContext $context, string $provider): string
    {
        return $this->resolver->bindingForTenant(ExternalTenantContext::tenantId($context), $provider)->callbackKey;
    }

    public function update(TenantContext $context, string $provider, array $config, string $identity): void
    {
        $tenantId = ExternalTenantContext::tenantId($context);
        $identity = strtolower(trim($identity));
        $enabled = self::enabled($provider, $config);
        self::assertValidInput($provider, $identity, $enabled);
        $this->store->updateBinding($tenantId, $provider, $config, $identity, $enabled);
    }

    /**
     * Atomically read, mutate and persist a Tenant binding under the adapter-owned row lock.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     * @param callable(array<string, mixed>): bool|null $enabledResolver
     */
    public function mutate(
        TenantContext $context,
        string $provider,
        string $identity,
        callable $mutator,
        ?callable $enabledResolver = null,
        ?string $identityHint = null,
    ): void {
        $tenantId = ExternalTenantContext::tenantId($context);
        $identity = strtolower(trim($identity));
        $this->store->mutateBinding(
            $tenantId,
            $provider,
            $identity,
            static function (array $current) use ($provider, $identity, $mutator, $enabledResolver): array {
                $config = $mutator($current);
                if (!is_array($config)) {
                    throw new \RuntimeException('外部渠道配置变更无效');
                }
                $enabled = $enabledResolver === null
                    ? self::enabled($provider, $config)
                    : (bool) $enabledResolver($config);
                self::assertValidInput($provider, $identity, $enabled);
                return ['config' => $config, 'enabled' => $enabled];
            },
            $identityHint,
        );
    }

    private function optionalBinding(TenantContext $context, string $provider): ?ExternalTenantBinding
    {
        $tenantId = ExternalTenantContext::tenantId($context);
        $bindings = $this->repository->byTenant($provider, $tenantId);
        if ($bindings === []) {
            if (!$this->store->tenantIsActive($tenantId)) {
                throw new ExternalTenantResolutionException();
            }
            return null;
        }
        return $this->resolver->bindingForTenant($tenantId, $provider, false);
    }

    private static function assertValidInput(string $provider, string $identity, bool $enabled): void
    {
        if (trim($provider) === '' || strlen($provider) > 64
            || ($enabled && $identity === '') || strlen($identity) > 191) {
            throw new \RuntimeException('外部渠道身份不能为空');
        }
    }

    private static function enabled(string $provider, array $config): bool
    {
        return match ($provider) {
            ExternalProvider::WECHAT_PAYMENT => (int) ($config['wx_pay_status'] ?? 0) === 1,
            ExternalProvider::ALIPAY_PAYMENT => (int) ($config['ali_pay_status'] ?? 0) === 1,
            ExternalProvider::WECHAT_OFFICIAL_CALLBACK => trim((string) ($config['token'] ?? '')) !== '',
            default => trim((string) ($config['app_id'] ?? '')) !== ''
                && trim((string) ($config['app_secret'] ?? '')) !== '',
        };
    }
}
