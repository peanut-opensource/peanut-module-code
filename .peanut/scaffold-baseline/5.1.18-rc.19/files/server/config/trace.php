<?php

// +----------------------------------------------------------------------
// | Trace设置 开启调试模式后有效
// +----------------------------------------------------------------------
return [
    // 内置Html和Console两种方式 支持扩展
    'type'    => 'Html',
    // 读取的日志通道名
    'channel' => '',
    'slow_request_ms' => (int) env('PEANUT_TRACE_SLOW_REQUEST_MS', 0),
    'max_sql_count' => (int) env('PEANUT_TRACE_MAX_SQL_COUNT', 0),
];
