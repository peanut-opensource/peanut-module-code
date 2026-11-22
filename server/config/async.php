<?php

declare(strict_types=1);

// The Task ModuleProvider reads the signing key and worker limit.

return [
    'signing_key' => env('ASYNC_SIGNING_KEY', ''),
    'worker_limit' => (int) env('ASYNC_WORKER_LIMIT', 25),
];
