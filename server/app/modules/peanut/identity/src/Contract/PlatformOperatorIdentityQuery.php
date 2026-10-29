<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

/** Minimal operator identity projection required by trusted platform workers. */
interface PlatformOperatorIdentityQuery
{
    public function accountId(int $operatorId): int;
}
