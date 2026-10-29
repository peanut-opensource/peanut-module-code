<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Contract;

use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** Read-only OAuth identity lookup exposed to dependent Modules. */
interface OAuthQueries
{
    public function wechatSubjectForMember(
        AuthenticatedMemberContext|TenantContext $context,
        int $memberId,
        int $terminal,
    ): string;
}
