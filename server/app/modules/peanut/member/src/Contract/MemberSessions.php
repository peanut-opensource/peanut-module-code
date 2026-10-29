<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionGrant;

/** 会员安全域的服务端会话合同；与后台员工会话相互独立。 */
interface MemberSessions
{
    public function issue(int $memberId, int $issuedAt, int $expiresAt): MemberSessionGrant;

    public function verify(
        string $sessionKey,
        int $tenantId,
        int $memberId,
        int $sessionRevision,
        int $issuedAt,
        int $expiresAt,
        int $now,
    ): void;

    public function revokeCurrent(
        string $sessionKey,
        int $tenantId,
        int $memberId,
        int $sessionRevision,
        int $issuedAt,
        int $expiresAt,
        int $now,
    ): void;

    public function revokeAll(int $tenantId, int $memberId, string $reason, int $now): void;
}
