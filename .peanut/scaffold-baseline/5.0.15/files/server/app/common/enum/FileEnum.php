<?php

declare(strict_types=1);

namespace app\common\enum;

/** 文件类型枚举，与 pa_file / pa_file_cate 的 type 字段对应 */
class FileEnum
{
    public const IMAGE = 10;
    public const VIDEO = 20;
    public const FILE  = 30;

    public const SOURCE_ADMIN = 0;
    public const SOURCE_USER  = 1;

    /** 各类型允许的扩展名白名单 */
    public const EXT = [
        self::IMAGE => ['jpg', 'png', 'gif', 'jpeg', 'webp', 'ico', 'bmp'],
        self::VIDEO => ['wmv', 'avi', 'mpg', 'mpeg', '3gp', 'mov', 'mp4', 'flv', 'f4v', 'rmvb', 'mkv'],
        self::FILE  => ['zip', 'rar', 'txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', '7z', 'gz'],
    ];

    /** 各类型上传大小上限（字节） */
    public const MAX_SIZE = [
        self::IMAGE => 10 * 1024 * 1024,   // 10MB
        self::VIDEO => 200 * 1024 * 1024,  // 200MB
        self::FILE  => 50 * 1024 * 1024,   // 50MB
    ];

    /** 各类型默认保存子目录 */
    public const SAVE_DIR = [
        self::IMAGE => 'uploads/images',
        self::VIDEO => 'uploads/videos',
        self::FILE  => 'uploads/files',
    ];

    public static function isValidType(int $type): bool
    {
        return in_array($type, [self::IMAGE, self::VIDEO, self::FILE], true);
    }
}
