<?php

declare(strict_types=1);

namespace app\platform\identity;

final readonly class PlatformOperatorIdentity
{
    public function __construct(
        public int $operatorId,
        public int $accountId,
    ) {
        if ($operatorId <= 0 || $accountId <= 0) {
            throw new \InvalidArgumentException('Platform operator identity is invalid.');
        }
    }
}
