<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract\Dto;

/** 会员会话签发时读取的权威归属与安全修订。 */
final readonly class MemberSessionSubject
{
    public function __construct(
        public int $tenantId,
        public int $memberId,
        public int $sessionRevision,
    ) {
        if ($this->tenantId < 1 || $this->memberId < 1 || $this->sessionRevision < 1) {
            throw new \InvalidArgumentException('MEMBER_SESSION_SUBJECT_INVALID');
        }
    }
}
