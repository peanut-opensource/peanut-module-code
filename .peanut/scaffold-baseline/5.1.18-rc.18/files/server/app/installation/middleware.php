<?php

declare(strict_types=1);

// Installation owns its same-origin, one-time setup-token and typed execution boundary.
return [
    \app\installation\http\middleware\InstallationExecutionMiddleware::class,
];
