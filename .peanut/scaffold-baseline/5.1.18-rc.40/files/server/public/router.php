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

// A decoded NUL cannot be used as a filesystem path (realpath would throw).
if (str_contains($requestPath, "\0")) {
    http_response_code(404);
    header('Cache-Control: no-store');
    return true;
}

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

// Stable Module public assets are published only from the locked Admin build.
// Unlike SPA routes, missing/retired assets must never return index.html.
$moduleAssetsPrefix = '/admin/module-assets';
if ($requestPath === $moduleAssetsPrefix || str_starts_with($requestPath, $moduleAssetsPrefix . '/')) {
    header('Cache-Control: no-store');
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        return true;
    }

    $relative = substr($requestPath, strlen($moduleAssetsPrefix) + 1);
    $safeName = preg_match('#^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)+$#D', $relative) === 1
        && preg_match('/\.php$/i', $relative) !== 1;
    $root = $safeName ? realpath(__DIR__ . '/admin/module-assets') : false;
    $file = $root === false ? false : realpath(__DIR__ . $requestPath);
    if (!$safeName || $root === false || $file === false
        || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)
        || !is_file($file)) {
        http_response_code(404);
        return true;
    }
    // PHP's built-in server discards router headers when returning false.
    // Serve the original bytes here so stable Module URLs keep no-store.
    $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'avif' => 'image/avif',
        'css' => 'text/css',
        'gif' => 'image/gif',
        'html' => 'text/html',
        'ico' => 'image/x-icon',
        'jpeg', 'jpg' => 'image/jpeg',
        'js', 'mjs' => 'text/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain',
        'wasm' => 'application/wasm',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'xml' => 'application/xml',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        readfile($file);
    }
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
