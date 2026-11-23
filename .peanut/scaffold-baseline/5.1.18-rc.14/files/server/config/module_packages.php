<?php

declare(strict_types=1);

$trusted = json_decode((string) env('PEANUT_MODULE_TRUSTED_KEYS_JSON', '{}'), true);

return [
    // Map Ed25519 key_id => base64 public key. Package keys never come from the archive itself.
    'trusted_ed25519_keys' => is_array($trusted) && !array_is_list($trusted) ? $trusted : [],
];
