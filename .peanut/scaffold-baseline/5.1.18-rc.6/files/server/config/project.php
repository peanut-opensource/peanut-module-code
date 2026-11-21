<?php

$brandManifest = json_decode((string) file_get_contents(__DIR__ . '/brand.json'), true);
$defaultImage = is_array($brandManifest['default_image'] ?? null)
    ? $brandManifest['default_image']
    : throw new RuntimeException('品牌默认图片配置格式错误');
$configuredVersion = trim((string) env('project.version', ''));

return [
    'version' => $configuredVersion !== '' ? $configuredVersion
        : \app\common\value\installation\ApplicationReleaseVersions::runningVersion(dirname(__DIR__)),
    'based' => 'Vue 3.x、Element Plus、ThinkPHP 8、MySQL',
    // 用途化的中性默认资源；品牌 logo/favicon 由 config/brand.json 拥有。
    'default_image' => [
        'admin_avatar'      => $defaultImage['admin_avatar'],
        'menu_admin'        => $defaultImage['menu'],
        'menu_role'         => $defaultImage['menu'],
        'menu_dept'         => $defaultImage['menu'],
        'menu_dict'         => $defaultImage['menu'],
        'menu_generator'    => $defaultImage['menu'],
        'menu_file'         => $defaultImage['menu'],
        'menu_auth'         => $defaultImage['menu'],
        'menu_web'          => $defaultImage['menu'],
        'project_docs'      => $defaultImage['project_docs'],
        'technical_support' => $defaultImage['technical_support'],
        'user_avatar'       => $defaultImage['user_avatar'],
    ],
];
