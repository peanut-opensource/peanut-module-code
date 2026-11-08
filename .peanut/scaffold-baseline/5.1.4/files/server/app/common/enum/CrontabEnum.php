<?php

declare(strict_types=1);

namespace app\common\enum;

/**
 * 定时任务枚举
 */
class CrontabEnum
{
    // 类型
    public const CRONTAB = 1; // 定时任务

    // 状态
    public const START = 1; // 运行
    public const STOP  = 2; // 停止
    public const ERROR = 3; // 错误

    public const TYPE_DESC = [
        self::CRONTAB => '定时任务',
    ];

    public const STATUS_DESC = [
        self::START => '运行',
        self::STOP  => '停止',
        self::ERROR => '错误',
    ];
}
