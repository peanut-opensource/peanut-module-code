<?php

declare(strict_types=1);

/**
 * Versioned exceptions to the default-deny Tenant Admin API policy.
 *
 * Permission-protected adminapi routes are deliberately not listed here:
 * their exact method/path must exist in the active API catalogue and resolve
 * an active permission bundle. Keep both exception sets small and method-specific.
 */
return [
    'version' => 1,
    'public' => [
        'POST adminapi/user/login',
        'POST adminapi/user/logout',
        'POST adminapi/tenant/session/login',
        'POST adminapi/tenant/session/select',
        'POST adminapi/tenant/session/switch',
        'POST adminapi/tenant/session/logout',
    ],
    'authenticated' => [
        'GET /adminapi/core.tenant.license',
        // File resolves its operation and current Document/File policy inside the use case.
        'POST adminapi/api/v1/files/resolve',
        'POST adminapi/user/info',
        'POST adminapi/user/menu',
        'GET adminapi/login/info',
        'GET adminapi/menu/route',
        'GET adminapi/admin/self',
        'POST adminapi/admin/editself',
    ],
    // Platform credentials and permission keys stay in their own audience.
    'platform_public' => [
        'POST platformapi/session/login',
        'POST platformapi/session/refresh',
        'POST platformapi/session/logout',
    ],
    'platform_authenticated' => [
        'GET platformapi/session/info',
    ],
];
