<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// $Id$

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = rawurldecode($requestPath);

// Match the deployed Nginx ingress: tenant objects are available only through
// File's controlled delivery route, which rechecks current visibility and grants.
// The development server must not serve an existing object as a static file.
$storageRoot = realpath(__DIR__ . '/storage');
$requestedFile = realpath(__DIR__ . '/' . ltrim($requestPath, '/'));
$staticFile = realpath(($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . ($_SERVER['SCRIPT_NAME'] ?? ''));
$isStorageFile = static fn($path): bool => $storageRoot !== false && $path !== false
    && ($path === $storageRoot || str_starts_with($path, $storageRoot . DIRECTORY_SEPARATOR));
if (preg_match('#^/storage(?:/|$)#', $requestPath) === 1
    || $isStorageFile($requestedFile) || $isStorageFile($staticFile)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    return true;
}

// The completion receipt is also the physical lock. No database probe may
// reopen the installer, including when that database is unavailable.
$installPage = preg_match('#^/(?:admin/)?install(?:/|$)#', $requestPath) === 1;
$installLock = dirname(__DIR__) . '/private/installation/installed.json';
if ($installPage && (file_exists($installLock) || is_link($installLock))) {
    http_response_code(404);
    header('Cache-Control: no-store');
    return true;
}
if (preg_match('#^/(?:admin/)?installation(?:/|$)#', $requestPath) === 1) {
    http_response_code(404);
    return true;
}
if ($installPage) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(__DIR__ . '/install/index.html');
    return true;
}

if ($requestPath === '/') {
    header('Location: /admin/', true, 302);
    return true;
}

if ($requestPath === '/admin') {
    header('Location: /admin/', true, 302);
    return true;
}

if (str_starts_with($requestPath, '/admin/')) {
    $staticPath = __DIR__ . $requestPath;

    if (is_file($staticPath)) {
        return false;
    }

    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/admin/index.html';
    readfile($_SERVER['SCRIPT_FILENAME']);
    return true;
}

if (is_file($_SERVER['DOCUMENT_ROOT'] . ($_SERVER['SCRIPT_NAME'] ?? ''))) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
return true;
