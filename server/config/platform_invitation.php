<?php

declare(strict_types=1);

return [
    // auto requires a configured delivery provider outside local/development.
    // manual returns the one-time link only to an authorized PlatformOperator.
    'delivery_mode' => env('OWNER_INVITATION_DELIVERY_MODE', 'auto'),
];
