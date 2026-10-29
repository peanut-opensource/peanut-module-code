<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract\Dto;

final readonly class MemberBalanceMutation
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public int $memberId,
        public int $changeType,
        public int $action,
        public int $amountCents,
        public string $sourceSn = '',
        public string $remark = '',
        public array $extra = [],
        public int $adminId = 0,
        public int $rechargeDeltaCents = 0,
        public string $insufficientMessage = '',
    ) {}
}
