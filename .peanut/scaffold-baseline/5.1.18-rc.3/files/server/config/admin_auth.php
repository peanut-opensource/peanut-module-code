<?php

declare(strict_types=1);

return [
    // 管理端登录失败限制。
    'password_error_times'  => (int) env('ADMIN_PASSWORD_ERROR_TIMES', 5),
    'lock_minutes'          => (int) env('ADMIN_LOGIN_LOCK_MINUTES', 30),
];
