<?php

declare(strict_types=1);

return [
    // Dedicated HMAC material for Tenant login identifiers; fail closed when absent/short.
    'identifier_hmac_key' => env('TENANT_IDENTIFIER_HMAC_KEY', ''),
];
