<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Service;

use PeanutAdmin\Modules\OAuth\Contract\OAuthQueries;
use PeanutAdmin\Modules\OAuth\Infrastructure\Persistence\ThinkPhpOAuthPersistence;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;

final class OAuthQueryService implements OAuthQueries
{
    public function __construct(private readonly ThinkPhpOAuthPersistence $persistence) {}

    public function wechatSubjectForMember(
        AuthenticatedMemberContext|TenantContext $context,
        int $memberId,
        int $terminal,
    ): string {
        if ($memberId < 1 || $terminal < 1) {
            return '';
        }

        return $this->persistence->wechatSubjectForMember($context, $memberId, $terminal);
    }
}
