<?php

declare(strict_types=1);

return [
    'machine_scopes' => (string) env('INTEGRATION_MACHINE_SCOPES', ''),
    'webhook_secret_key_id' => (string) env('INTEGRATION_WEBHOOK_SECRET_KEY_ID', ''),
    'webhook_secret_key' => (string) env('INTEGRATION_WEBHOOK_SECRET_KEY', ''),
];
