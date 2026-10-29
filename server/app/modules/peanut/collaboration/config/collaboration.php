<?php

declare(strict_types=1);

$secret = env('COLLABORATION_SECRET', '');
if (!is_string($secret) || ($secret !== '' && (strlen($secret) < 32 || strlen($secret) > 4096
    || preg_match('/[\r\n\x00]/', $secret)))) {
    throw new RuntimeException('COLLABORATION_SECRET_INVALID');
}

return [
    'enabled' => env('COLLABORATION_ENABLED', 'disabled') === 'enabled',
    'environment' => env('APP_ENV', 'production'),
    // Stable deployment identity, never a hostname or a client-provided Room name.
    'instance_id' => env('COLLABORATION_INSTANCE_ID', ''),
    'service_instance_id' => env('COLLABORATION_SERVICE_INSTANCE_ID', ''),
    'key_id' => env('COLLABORATION_SERVICE_KEY_ID', ''),
    'secret' => $secret,
    // Explicit HTTP origins are development-only; HTTPS always verifies the configured CA.
    'control_origin' => env('COLLABORATION_CONTROL_ORIGIN', ''),
    // The historical key also carries an explicit ws:// URL in development.
    'public_wss_url' => env('COLLABORATION_PUBLIC_WSS_URL', ''),
    'ca_bundle_file' => env('COLLABORATION_CA_FILE', '') === 'private/collaboration/service/ca.pem'
        ? dirname(__DIR__, 5) . '/private/collaboration/service/ca.pem' : '',
    'ca_sha256' => env('COLLABORATION_CA_SHA256', ''),
    'clock_window_seconds' => 30,
    'connection_lease_seconds' => 15,
    'room_lease_seconds' => 30,
    'max_request_bytes' => 8388608,
    // Bounded native baseline/capture, base64 expansion and metadata.
    'max_response_bytes' => 33554432,
    'projector_version' => 'peanut.richtext-yjs/1',
];
