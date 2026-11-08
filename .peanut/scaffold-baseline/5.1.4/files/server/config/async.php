<?php

declare(strict_types=1);

return [
    'signing_key' => env('ASYNC_SIGNING_KEY', ''),
    'worker_limit' => (int) env('ASYNC_WORKER_LIMIT', 25),
];
