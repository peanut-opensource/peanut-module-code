<?php

$databaseName = env('DB_NAME', '');
$databaseUser = env('DB_USER', '');
$databasePassword = env('DB_PASS', null);
// Configuration must load before first setup. An absent connection is rejected
// by ThinkPHP when used; an explicitly configured empty password remains valid.
$databaseConfigured = $databaseName !== '' && $databaseUser !== '' && $databasePassword !== null;

$databaseConnectTimeout = env('DB_CONNECT_TIMEOUT_SECONDS', 5);
if (
    (!is_int($databaseConnectTimeout) && !is_string($databaseConnectTimeout))
    || preg_match('/^[1-9][0-9]*$/D', (string) $databaseConnectTimeout) !== 1
    || (int) $databaseConnectTimeout > 30
) {
    throw new RuntimeException('BACKEND_ENVIRONMENT_INVALID:DB_CONNECT_TIMEOUT_SECONDS');
}
$databaseConnectTimeout = (int) $databaseConnectTimeout;

return [
    // 默认使用的数据库连接配置
    'default'         => env('DB_DRIVER', 'mysql'),

    // 自定义时间查询规则
    'time_query_rule' => [],

    // 自动写入时间戳字段
    // true为自动识别类型 false关闭
    // 字符串则明确指定时间字段类型 支持 int timestamp datetime date
    'auto_timestamp'  => true,

    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',

    // 时间字段配置 配置格式：create_time,update_time
    'datetime_field'  => '',

    // 数据库连接配置信息
    'connections'     => $databaseConfigured ? [
        'mysql' => [
            // 数据库类型
            'type'            => env('DB_TYPE', 'mysql'),
            // 服务器地址
            'hostname'        => env('DB_HOST', '127.0.0.1'),
            // 数据库名
            'database'        => $databaseName,
            // 用户名
            'username'        => $databaseUser,
            // 密码
            'password'        => $databasePassword,
            // 端口
            'hostport'        => env('DB_PORT', '3306'),
            // 数据库连接参数
            'params'          => [
                PDO::ATTR_TIMEOUT => $databaseConnectTimeout,
            ],
            // 数据库编码
            'charset'         => env('DB_CHARSET', 'utf8mb4'),
            // 数据库表前缀
            'prefix'          => env('DB_PREFIX', 'pa_'),

            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 0,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => false,
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => true,
            // 是否需要断线重连
            'break_reconnect' => false,
            // Preserve normal debug logging; an owner-selected FPM sample enables this connection only after its safe listener is installed.
            'trigger_sql'     => env('APP_DEBUG', false),
            // 开启字段缓存
            'fields_cache'    => false,
        ],

        // 更多的数据库配置信息
    ] : [],
];
