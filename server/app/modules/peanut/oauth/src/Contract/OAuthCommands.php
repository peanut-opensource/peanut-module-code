<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Contract;

use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthAuthorizationResult;
use PeanutAdmin\Modules\OAuth\Contract\Dto\OAuthLoginResult;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/**
 * OAuth's write and callback boundary. Implementations consume state/tickets
 * atomically and delegate member, notification and channel ownership to their
 * respective Module contracts.
 */
interface OAuthCommands
{
    public function begin(TenantSystemContext $context, string $scene, string $returnPath, string $redirectUri, ExternalTenantBinding $binding): OAuthAuthorizationResult;

    public function callback(TenantSystemContext $context, string $scene, string $code, string $state, ExternalTenantBinding $binding, string $ip): OAuthLoginResult;

    public function miniProgramLogin(TenantSystemContext $context, string $code, ExternalTenantBinding $binding, string $ip): OAuthLoginResult;

    public function complete(TenantContext|TenantSystemContext $context, array $params, string $ip): OAuthLoginResult;

    public function bind(AuthenticatedMemberContext $context, int $memberId, string $scene, string $code): bool;

}
