<?php

declare(strict_types=1);

if (!function_exists('linear_to_tree')) {
    function linear_to_tree(array $data, string $childrenKey = 'children', string $idKey = 'id', string $pidKey = 'pid'): array
    {
        $map = [];
        foreach ($data as $item) {
            $item = is_array($item) ? $item : (method_exists($item, 'toArray') ? $item->toArray() : (array) $item);
            $map[$item[$idKey]] = $item;
            $map[$item[$idKey]][$childrenKey] = [];
        }
        $tree = [];
        foreach ($map as &$item) {
            $pid = $item[$pidKey] ?? 0;
            if ($pid == 0 || !isset($map[$pid])) {
                $tree[] = &$item;
            } else {
                $map[$pid][$childrenKey][] = &$item;
            }
        }
        unset($item);
        return $tree;
    }
}

if (!function_exists('compare_php')) {
    /** 比较当前 PHP 版本是否 >= 指定版本 */
    function compare_php(string $version): bool
    {
        return version_compare(PHP_VERSION, $version) >= 0;
    }
}

if (!function_exists('check_dir_write')) {
    /** 检查项目根目录下某子目录是否可写 */
    function check_dir_write(string $dir = ''): bool
    {
        $route = root_path() . $dir;
        return is_writable($route);
    }
}

if (!function_exists('del_target_dir')) {
    /** 递归清空目录内容；$delDir=true 时连同目录本身一并删除 */
    function del_target_dir(string $path, bool $delDir): bool
    {
        if (!file_exists($path)) {
            return false;
        }
        $handle = opendir($path);
        if ($handle === false) {
            return file_exists($path) ? unlink($path) : false;
        }
        while (false !== ($item = readdir($handle))) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                del_target_dir($full, $delDir);
            } else {
                unlink($full);
            }
        }
        closedir($handle);
        if ($delDir) {
            return rmdir($path);
        }
        return true;
    }
}
