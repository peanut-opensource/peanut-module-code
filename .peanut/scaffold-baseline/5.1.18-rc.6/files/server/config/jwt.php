<?php

declare(strict_types=1);
return [
    // Signing is deliberately unavailable until deployment provides a secret.
    'secret' => env('JWT_SECRET'),
    'expire' => (int) env('JWT_EXPIRE', 7200),
];
