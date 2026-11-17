<?php

declare(strict_types=1);

namespace app\adminapi\services\system;

use think\facade\Cache;

/**
 * 系统维护逻辑
 * Class SystemApplicationService
 * @package app\adminapi\services\system
 */
class SystemApplicationService
{
    /**
     * 系统环境信息
     */
    public function getInfo(string $serverSoftware): array
    {
        $server = [
            ['param' => '服务器操作系统', 'value' => PHP_OS],
            ['param' => 'web服务器环境', 'value' => $serverSoftware !== '' ? $serverSoftware : 'unknown'],
            ['param' => 'PHP版本', 'value' => PHP_VERSION],
            ['param' => '上传附件限制', 'value' => ini_get('upload_max_filesize')],
        ];

        $env = [
            [
                'option'  => 'PHP版本',
                'require' => '8.3版本以上',
                'status'  => (int) version_compare(PHP_VERSION, '8.3.0', '>='),
                'remark'  => '',
            ],
        ];

        $auth = [
            [
                'dir'     => '/runtime',
                'require' => 'runtime目录可写',
                'status'  => self::directoryWritable('runtime'),
                'remark'  => '',
            ],
            [
                'dir'     => '/public/storage',
                'require' => 'storage目录可写',
                'status'  => self::directoryWritable('public/storage'),
                'remark'  => '',
            ],
            [
                'dir'     => '/private/storage',
                'require' => 'private/storage目录可写且不公开',
                'status'  => self::directoryWritable('private/storage'),
                'remark'  => '',
            ],
        ];

        return [
            'server' => $server,
            'env'    => $env,
            'auth'   => $auth,
        ];
    }

    /**
     * 清除系统缓存
     */
    public function clearCache(): bool
    {
        Cache::tag('application:v1')->clear();
        del_target_dir(root_path() . 'runtime/file', true);
        return true;
    }

    /** 只读取目录元数据，不创建探针文件。 */
    private static function directoryWritable(string $relativePath): int
    {
        $path = root_path() . trim($relativePath, '/');
        return (int) (is_dir($path) && is_readable($path) && is_writable($path));
    }
}
