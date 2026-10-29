<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract\Dto;

final readonly class MemberIdentitySnapshot
{
    public function __construct(
        public int $id,
        public string $sn,
        public string $nickname,
        public string $avatar,
        public string $mobile,
        public int $status,
    ) {}
}
