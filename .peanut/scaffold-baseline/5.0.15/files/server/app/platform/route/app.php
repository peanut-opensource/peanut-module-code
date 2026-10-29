<?php

declare(strict_types=1);

$peanutRouteApplication = 'platform';
require dirname(__DIR__, 3) . '/route/platform.php';
require dirname(__DIR__, 3) . '/app/modules/peanut/file/route/app.php';
unset($peanutRouteApplication);
