<?php

declare(strict_types=1);

// The Integration ModuleProvider reads the machine scopes and webhook signing key.

return [
    'machine_scopes' => (string) env('INTEGRATION_MACHINE_SCOPES', ''),
    'webhook_secret_key_id' => (string) env('INTEGRATION_WEBHOOK_SECRET_KEY_ID', ''),
    'webhook_secret_key' => (string) env('INTEGRATION_WEBHOOK_SECRET_KEY', ''),
];
