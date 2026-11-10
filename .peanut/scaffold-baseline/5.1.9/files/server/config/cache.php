<?php

use app\common\value\runtime\RuntimeNamespace;
use app\common\infrastructure\runtime\FileCache;

$runtimeNamespace = RuntimeNamespace::fromDatabaseConfiguration(
    require __DIR__ . '/database.php',
    (string) env('APP_ENV', 'production'),
);

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动
    'default' => 'file',

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => FileCache::class,
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => $runtimeNamespace->cachePrefix(),
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => $runtimeNamespace->cacheTagPrefix(),
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // 更多的缓存连接
    ],
];
