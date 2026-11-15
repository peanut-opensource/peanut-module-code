<?php

declare(strict_types=1);

return [
    // New credentials only; lengths count UTF-8 bytes. Existing login credentials are unaffected.
    'password' => [
        'minimum_length' => 6,
        'maximum_length' => 32,
        'require_uppercase' => false, // ASCII A-Z
        'require_lowercase' => false, // ASCII a-z
        'require_digit' => false, // ASCII 0-9
        'require_special' => false, // Printable ASCII punctuation, excluding space
    ],
    // 稳定 override key => 实现 AdminPermissionPolicy 的应用类。
    'overrides' => [],
    // module_key => [setting_key => value]; DB deployment/tenant/target values take precedence.
    // Use env() for deployment secrets; keep real credentials out of Module packages.
    'settings' => [],
    'environment' => env('APP_ENV', 'production'),
    'demo' => [
        'enabled' => env('PEANUT_DEMO_MODE', 'disabled') === 'enabled',
        'admin_email' => env('PEANUT_DEMO_ADMIN_EMAIL', ''),
        'platform_email' => env('PEANUT_DEMO_PLATFORM_EMAIL', ''),
        'tenant_a_email' => env('PEANUT_DEMO_TENANT_A_EMAIL', ''),
        'tenant_b_email' => env('PEANUT_DEMO_TENANT_B_EMAIL', ''),
        'tenant_a_host' => env('PEANUT_DEMO_TENANT_A_HOST', ''),
        'tenant_b_host' => env('PEANUT_DEMO_TENANT_B_HOST', ''),
        'shared_password' => env('PEANUT_DEMO_SHARED_PASSWORD', ''),
    ],
    'settings_secrets' => [
        'keys' => env('PEANUT_SETTINGS_SECRET_KEYS', ''),
        'active_key_id' => env('PEANUT_SETTINGS_ACTIVE_SECRET_KEY_ID', ''),
    ],
    'storage_credential_master_key' => env('PEANUT_STORAGE_CREDENTIAL_MASTER_KEY', ''),
    'installation' => [
        'mode' => env('PEANUT_INSTALLATION_MODE', 'automatic'),
        'setup_token' => env('PEANUT_INSTALLATION_SETUP_TOKEN', ''),
    ],
];
