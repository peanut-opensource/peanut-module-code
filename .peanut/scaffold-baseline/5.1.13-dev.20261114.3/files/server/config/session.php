<?php

use app\common\value\runtime\RuntimeNamespace;

$runtimeNamespace = RuntimeNamespace::fromDatabaseConfiguration(
    require __DIR__ . '/database.php',
    (string) env('APP_ENV', 'production'),
);

// +----------------------------------------------------------------------
// | 会话设置
// +----------------------------------------------------------------------

return [
    // session name
    'name'           => $runtimeNamespace->sessionName(),
    // SESSION_ID的提交变量,解决flash上传跨域
    'var_session_id' => '',
    // 驱动方式 支持file cache
    'type'           => 'file',
    // 存储连接标识 当type使用cache的时候有效
    'store'          => null,
    // 过期时间
    'expire'         => 1440,
    // 前缀
    'prefix'         => $runtimeNamespace->sessionPrefix(),
];
