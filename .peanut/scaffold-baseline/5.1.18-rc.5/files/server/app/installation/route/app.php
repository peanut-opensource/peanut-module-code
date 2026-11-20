<?php

declare(strict_types=1);

$peanutRouteApplication = 'installation';
require dirname(__DIR__, 3) . '/route/app.php';
unset($peanutRouteApplication);
