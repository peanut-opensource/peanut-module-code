<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Contract\Dto;

final readonly class OAuthPrincipalRecord
{
    public function __construct(
        public int $id,
        public int $memberId,
    ) {}
}
