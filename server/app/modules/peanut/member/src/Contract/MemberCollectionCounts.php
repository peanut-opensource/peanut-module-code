<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;

/** Optional content-provider contribution to the current member's center summary. */
interface MemberCollectionCounts
{
    public function countForMember(AuthenticatedMemberContext $context, int $memberId): int;
}
