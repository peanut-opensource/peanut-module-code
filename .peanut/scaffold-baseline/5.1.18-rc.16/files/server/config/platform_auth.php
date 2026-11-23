<?php

declare(strict_types=1);

return [
    // Dedicated HMAC material for platform login identifiers; fail closed when absent/short.
    'identifier_hmac_key' => env('PLATFORM_IDENTIFIER_HMAC_KEY', ''),
];
