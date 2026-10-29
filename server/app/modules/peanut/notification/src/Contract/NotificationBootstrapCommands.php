<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

use app\common\execution\SystemExecutionContext;

interface NotificationBootstrapCommands
{
    public function provisionTenantDefaults(SystemExecutionContext $context): void;
}
