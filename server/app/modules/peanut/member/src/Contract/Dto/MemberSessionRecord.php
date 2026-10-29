<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract\Dto;

/** 会员会话持久化记录；不保存 JWT、密码或可直接使用的会话密钥。 */
final readonly class MemberSessionRecord
{
    public function __construct(
        public string $sessionHash,
        public int $tenantId,
        public int $memberId,
        public int $sessionRevision,
        public int $issuedAt,
        public int $expiresAt,
        public ?int $revokedAt = null,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $this->sessionHash) !== 1
            || $this->tenantId < 1
            || $this->memberId < 1
            || $this->sessionRevision < 1
            || $this->issuedAt < 1
            || $this->expiresAt <= $this->issuedAt
            || ($this->revokedAt !== null && $this->revokedAt < $this->issuedAt)) {
            throw new \InvalidArgumentException('MEMBER_SESSION_RECORD_INVALID');
        }
    }
}
