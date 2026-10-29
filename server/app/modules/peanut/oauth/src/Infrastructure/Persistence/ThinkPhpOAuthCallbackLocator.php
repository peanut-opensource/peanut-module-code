<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Infrastructure\Persistence;

use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCallbackLocator;
use PeanutAdmin\Modules\OAuth\Model\OAuthAttempt;
use PeanutAdmin\Modules\OAuth\Model\OAuthCompletionTicket;

final class ThinkPhpOAuthCallbackLocator implements OAuthCallbackLocator
{
    public function __construct(private readonly ExternalTenantResolutionService $bindings) {}

    public function locateState(string $provider, string $stateHash): array
    {
        $scene = match ($provider) {
            ExternalProvider::WECHAT_OFFICIAL_OAUTH => 'oa',
            ExternalProvider::WECHAT_OPEN_PLATFORM => 'open_pc',
            default => null,
        };
        if ($scene === null) {
            return [];
        }

        $attempts = OAuthAttempt::callbackCandidates()
            ->field('tenant_id')
            ->where('state_hash', $stateHash)
            ->where('scene', $scene)
            ->whereNull('used_at')
            ->where('expires_at', '>=', time())
            ->limit(2)->select()->toArray();
        return array_map(fn(array $attempt): ExternalTenantBinding =>
            $this->bindings->bindingForCallbackReference((int) $attempt['tenant_id'], $provider), $attempts);
    }

    public function locateTicket(string $ticketHash): array
    {
        $tickets = OAuthCompletionTicket::callbackCandidates()
            ->field('tenant_id,binding_id')
            ->where('token_hash', $ticketHash)
            ->whereNull('used_at')
            ->where('expires_at', '>=', time())
            ->limit(2)->select()->toArray();
        return array_map(fn(array $ticket): ExternalTenantBinding =>
            $this->bindings->bindingForCallbackReference((int) $ticket['tenant_id'], null, (int) $ticket['binding_id']), $tickets);
    }
}
