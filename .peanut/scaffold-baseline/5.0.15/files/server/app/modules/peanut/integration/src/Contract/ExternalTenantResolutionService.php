<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

interface ExternalTenantResolutionService
{
    public const SYSTEM_ACTOR = 'peanut.external.callback';

    public function verifiedCallback(string $provider, string $callbackKey, string $operation, string $operationId, callable $verifier): ExternalTenantResolution;

    public function clientIdentity(string $provider, string $clientIdentity, string $operation, string $operationId): ExternalTenantResolution;

    public function onlyActiveBinding(string $provider, string $operation, string $operationId): ExternalTenantResolution;

    /** @param list<ExternalTenantBinding> $bindings */
    public function verifiedCandidates(array $bindings, string $provider, string $candidate, string $operation, string $operationId, bool $requireProviderMatch = true): ExternalTenantResolution;

    public function bindingForTenant(int $tenantId, string $provider, bool $requireActive = true): ExternalTenantBinding;

    /**
     * Trusted payment-grant lookup, not an authorization grant. Missing selection returns null;
     * ambiguous or malformed ownership fails. A locked read joins the caller's transaction,
     * locking the Identity tenant before the Integration binding. Disabled bindings are kept
     * as candidates so the payment use case retains its distinct configure/grant rules.
     */
    public function bindingForGrant(int $tenantId, string $provider, ?int $bindingId = null, bool $forUpdate = false): ?ExternalTenantBinding;

    /**
     * Resolve a reference from an existing OAuth state/ticket, never client-supplied ownership.
     * Provider may be omitted only for an exact binding ID. This is an unverified candidate:
     * the caller must still use verifiedCandidates and the OAuth one-time/expiry checks.
     * Missing/orphan/ambiguous references fail instead of disappearing from a candidate set.
     */
    public function bindingForCallbackReference(int $tenantId, ?string $provider, ?int $bindingId = null): ExternalTenantBinding;
}
