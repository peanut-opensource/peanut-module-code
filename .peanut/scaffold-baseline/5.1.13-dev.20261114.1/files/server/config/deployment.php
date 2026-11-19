<?php

declare(strict_types=1);

return [
    // Instance-wide developer and maintenance tools require an explicit mode.
    // Missing or unknown values remain closed instead of becoming standalone.
    'mode' => env('DEPLOYMENT_MODE'),
    // Platform entry hosts are instance configuration. Tenant Admin and member
    // entry hosts are active Identity database bindings, including default.
    'platform_hosts' => env('PLATFORM_HOSTS', ''),
];
