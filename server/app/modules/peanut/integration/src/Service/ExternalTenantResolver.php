<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Service;

use PeanutAdmin\Modules\Integration\Contract\ExternalTenantAudit;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolution;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** Framework-agnostic resolver for a uniquely owned active external channel. */
final class ExternalTenantResolver implements ExternalTenantResolutionService
{
    public function __construct(
        private readonly ExternalTenantBindingRepository $bindings,
        private readonly ExternalTenantAudit $audit,
    ) {}

    public function verifiedCallback(string $provider, string $callbackKey, string $operation, string $operationId, callable $verifier): ExternalTenantResolution
    {
        return $this->resolve($provider, $callbackKey, $operation, $operationId, fn(): array => $this->bindings->byCallbackKey($provider, $callbackKey), $verifier);
    }

    public function clientIdentity(string $provider, string $clientIdentity, string $operation, string $operationId): ExternalTenantResolution
    {
        $canonical = self::canonicalIdentity($clientIdentity);
        return $this->resolve($provider, $canonical, $operation, $operationId, fn(): array => $this->bindings->byClientIdentity($provider, hash('sha256', $canonical)));
    }

    public function onlyActiveBinding(string $provider, string $operation, string $operationId): ExternalTenantResolution
    {
        return $this->resolve($provider, 'server-only-active-binding', $operation, $operationId, fn(): array => $this->bindings->byProvider($provider));
    }

    /** @param list<ExternalTenantBinding> $bindings */
    public function verifiedCandidates(
        array $bindings,
        string $provider,
        string $candidate,
        string $operation,
        string $operationId,
        bool $requireProviderMatch = true,
    ): ExternalTenantResolution {
        return $this->resolve(
            $provider,
            $candidate,
            $operation,
            $operationId,
            static fn(): array => $bindings,
            null,
            $requireProviderMatch,
        );
    }

    public function bindingForTenant(int $tenantId, string $provider, bool $requireActive = true): ExternalTenantBinding
    {
        if ($tenantId < 1) {
            throw new ExternalTenantResolutionException();
        }
        return $this->oneAvailable($this->bindings->byTenant($provider, $tenantId), $provider, 'tenant:' . $tenantId, $requireActive);
    }

    public function bindingForGrant(int $tenantId, string $provider, ?int $bindingId = null, bool $forUpdate = false): ?ExternalTenantBinding
    {
        if ($tenantId < 1 || ($bindingId !== null && $bindingId < 1)
            || !in_array($provider, [ExternalProvider::WECHAT_PAYMENT, ExternalProvider::ALIPAY_PAYMENT], true)) {
            throw new ExternalTenantResolutionException();
        }
        $bindings = $this->bindings->byTenant($provider, $tenantId, $forUpdate);
        if (count($bindings) > 1) {
            throw new ExternalTenantResolutionException();
        }
        $binding = $bindings[0] ?? null;
        if ($binding === null || ($bindingId !== null && $binding->id !== $bindingId)) {
            return null;
        }
        if ($binding->id < 1 || $binding->tenantId !== $tenantId || !hash_equals($provider, $binding->provider)) {
            throw new ExternalTenantResolutionException();
        }
        return $binding;
    }

    public function bindingForCallbackReference(int $tenantId, ?string $provider, ?int $bindingId = null): ExternalTenantBinding
    {
        $oauthProviders = [ExternalProvider::WECHAT_OFFICIAL_OAUTH, ExternalProvider::WECHAT_OPEN_PLATFORM, ExternalProvider::WECHAT_MINI_PROGRAM];
        if ($tenantId < 1 || ($bindingId !== null && $bindingId < 1)
            || ($provider === null && $bindingId === null)
            || ($provider !== null && !in_array($provider, $oauthProviders, true))) {
            throw new ExternalTenantResolutionException();
        }
        $bindings = $bindingId === null
            ? $this->bindings->byTenant((string) $provider, $tenantId)
            : $this->bindings->byReference($tenantId, $bindingId);
        if (count($bindings) !== 1) {
            throw new ExternalTenantResolutionException();
        }
        $binding = $bindings[0];
        if ($binding->id < 1 || $binding->tenantId !== $tenantId
            || ($bindingId !== null && $binding->id !== $bindingId)
            || !in_array($binding->provider, $oauthProviders, true)
            || ($provider !== null && !hash_equals($provider, $binding->provider))) {
            throw new ExternalTenantResolutionException();
        }
        // Keep inactive candidates visible to the existing authorization resolver;
        // filtering here could turn an ambiguous callback into one accepted owner.
        return $binding;
    }

    private function resolve(string $provider, string $candidate, string $operation, string $operationId, callable $lookup, ?callable $verifier = null, bool $requireProviderMatch = true): ExternalTenantResolution
    {
        $fingerprint = self::fingerprint($provider, $candidate);
        try {
            if (trim($provider) === '' || trim($candidate) === '' || trim($operationId) === '') {
                throw new \RuntimeException('invalid candidate');
            }
            $binding = $this->oneAvailable($lookup(), $provider, $candidate, true, $requireProviderMatch);
            $verifiedValue = $verifier === null ? null : $verifier($binding->config);
            if ($verifier !== null && $verifiedValue === false) {
                throw new \RuntimeException('provider verification failed');
            }
            $context = new TenantSystemContext($binding->tenantId, ExternalTenantResolutionService::SYSTEM_ACTOR, trim($operation), trim($operationId));
            $this->audit->record('accepted', ['provider' => $provider, 'identity' => $fingerprint, 'binding_id' => $binding->id, 'tenant_id' => $binding->tenantId, 'operation_id' => trim($operationId)]);
            return new ExternalTenantResolution($context, $binding, $verifiedValue);
        } catch (\Throwable) {
            $this->audit->record('rejected', ['provider' => trim($provider), 'identity' => $fingerprint, 'operation_id' => trim($operationId)]);
            throw new ExternalTenantResolutionException();
        }
    }

    /** @param list<ExternalTenantBinding> $bindings */
    private function oneAvailable(array $bindings, string $provider, string $candidate, bool $requireActive = true, bool $requireProviderMatch = true): ExternalTenantBinding
    {
        if (count($bindings) !== 1) {
            throw new ExternalTenantResolutionException();
        }
        $binding = $bindings[0];
        if ($binding->id < 1 || $binding->tenantId < 1 || ($requireActive && !$binding->active) || !$binding->tenantActive || ($requireProviderMatch && !hash_equals($provider, $binding->provider))) {
            throw new ExternalTenantResolutionException();
        }
        return $binding;
    }

    private static function canonicalIdentity(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 191) {
            throw new ExternalTenantResolutionException();
        }
        return $value;
    }

    private static function fingerprint(string $provider, string $candidate): string
    {
        return substr(hash('sha256', trim($provider) . "\0" . trim($candidate)), 0, 16);
    }
}
