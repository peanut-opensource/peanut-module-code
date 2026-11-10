<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$root = realpath(getenv('PEANUT_FORMAT_ROOT') ?: dirname(__DIR__, 2));
if ($root === false) {
    throw new RuntimeException('FORMAT_SOURCE_ROOT_UNAVAILABLE');
}
$process = proc_open(['git', '-C', $root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('FORMAT_GIT_UNAVAILABLE');
}
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('FORMAT_GIT_FAILED: ' . trim($error));
}
$files = [];
foreach (array_unique(explode("\0", $output)) as $relative) {
    if ($relative === '' || preg_match('#(?:^|/)(?:vendor|node_modules|\.local|\.worktrees|\.git)(?:/|$)#', $relative)
        || str_starts_with($relative, 'scaffold/releases/') || str_starts_with($relative, 'scaffold/legacy/')
        || str_contains($relative, '/tests/fixtures/') || str_starts_with($relative, 'tests/fixtures/')
        || str_contains($relative, '/database/migrations/') || str_contains($relative, '/__snapshots__/')) {
        continue;
    }
    $path = $root . '/' . $relative;
    if (!is_file($path) || is_link($path) || !str_starts_with(realpath($path), $root . '/')) {
        continue;
    }
    if (str_ends_with($relative, '.php') || str_starts_with((string) file_get_contents($path, false, null, 0, 30), '#!/usr/bin/env php')) {
        $files[] = $path;
    }
}
$finder = Finder::create()->append($files);

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules(['@PER-CS2x0' => true])
    ->setUsingCache(false)
    ->setFinder($finder);
