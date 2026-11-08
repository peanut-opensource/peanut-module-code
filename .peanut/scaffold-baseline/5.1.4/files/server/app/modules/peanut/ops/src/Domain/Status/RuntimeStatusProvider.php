<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Domain\Status;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface RuntimeStatusProvider
{
    public function snapshot(PlatformContext $context): OpsStatusSnapshot;
}
