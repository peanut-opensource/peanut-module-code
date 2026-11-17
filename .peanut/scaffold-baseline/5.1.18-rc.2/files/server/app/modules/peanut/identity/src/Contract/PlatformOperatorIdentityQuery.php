<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

/** Minimal operator identity projection required by trusted platform workers. */
interface PlatformOperatorIdentityQuery
{
    /** @throws \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException When the operator identity is unavailable. */
    public function accountId(int $operatorId): int;
}
