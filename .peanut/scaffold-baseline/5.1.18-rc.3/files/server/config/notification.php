<?php

declare(strict_types=1);

// Only explicit instance overrides belong here; each consumer owns its defaults.
$verificationOverrides = [];
foreach ([
    'max_failed_attempts' => ['NOTIFICATION_VERIFICATION_MAX_FAILURES', 1],
    'entry_max_failed_attempts' => ['NOTIFICATION_VERIFICATION_ENTRY_MAX_FAILURES', 1],
    'entry_window_seconds' => ['NOTIFICATION_VERIFICATION_ENTRY_WINDOW_SECONDS', 60],
] as $key => [$environmentKey, $minimum]) {
    $value = env($environmentKey);
    if ($value !== null) {
        $verificationOverrides[$key] = max($minimum, (int) $value);
    }
}

return ['verification' => $verificationOverrides];
