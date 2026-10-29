<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionSubject;

/** Resolves the owning Tenant for an active member authentication subject. */
interface MemberSubjectLookup
{
    public function tenantId(int $memberId): ?int;

    /** 返回启用会员的权威租户归属和当前会话安全修订。 */
    public function sessionSubject(int $memberId): ?MemberSessionSubject;
}
