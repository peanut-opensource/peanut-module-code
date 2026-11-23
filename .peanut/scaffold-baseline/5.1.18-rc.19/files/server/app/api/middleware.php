<?php

declare(strict_types=1);

return [
    \app\common\http\middleware\InstallationStateMiddleware::class,
    \app\common\http\middleware\MaintenanceWriteGateMiddleware::class,
];
