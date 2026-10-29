<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract\Dto;

/**
 * 新签发的会员会话。sessionKey 只进入访问凭证，持久层仅保存其 SHA-256 摘要。
 */
final readonly class MemberSessionGrant
{
    public function __construct(
        public string $sessionKey,
        public int $tenantId,
        public int $memberId,
        public int $sessionRevision,
        public int $issuedAt,
        public int $expiresAt,
    ) {
        if ($this->sessionKey === ''
            || $this->tenantId < 1
            || $this->memberId < 1
            || $this->sessionRevision < 1
            || $this->issuedAt < 1
            || $this->expiresAt <= $this->issuedAt) {
            throw new \InvalidArgumentException('MEMBER_SESSION_GRANT_INVALID');
        }
    }
}
