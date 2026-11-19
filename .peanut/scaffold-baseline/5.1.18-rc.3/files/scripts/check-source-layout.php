#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 只读跨仓源码布局检查。Git 路径而非宿主文件系统决定大小写，PHP tokenizer 只分析、不执行业务源码。
 * --root 可重复：传入各自 Git 根；不猜私有 Project 或本机路径，不读取 vendor、秘密或历史发行正文。
 * 严格检查生产 PSR-4 声明和已知类型引用大小写；测试/未知外部引用只提供范围，不冒充运行验收。
 */
function layoutGit(string $root, array $arguments): string
{
    $process = proc_open(['git', '-C', $root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('LAYOUT_GIT_UNAVAILABLE');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('LAYOUT_GIT_FAILED: ' . trim($stderr));
    }
    return $stdout;
}

function layoutHistorical(string $path): bool
{
    return str_starts_with($path, 'scaffold/releases/') || str_starts_with($path, 'scaffold/legacy/');
}

function layoutDevelopmentOnly(string $path): bool
{
    return preg_match('~(?:^|/)(?:tests|fixtures|testing)(?:/|$)~', $path) === 1;
}

/** @return list<string> */
function layoutImports(string $source): array
{
    if (preg_match('/^\s*(?:function|const)\s+/', $source)) {
        return [];
    }
    $prefix = '';
    if (str_contains($source, '{')) {
        [$prefix, $source] = explode('{', $source, 2);
        $source = rtrim($source, '}');
    }
    $names = [];
    foreach (explode(',', $source) as $item) {
        $item = trim($item);
        if (preg_match('/^(?:function|const)\s+/', $item)) {
            continue;
        }
        $item = preg_split('/\s+as\s+/i', $item)[0];
        $name = ltrim(trim($prefix . $item), '\\');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $name)) {
            $names[] = $name;
        }
    }
    return $names;
}

/** @return array{declarations:array<string,int>,references:list<array{name:string,line:int}>} */
function layoutSymbols(string $source): array
{
    $tokens = token_get_all($source);
    $namespace = '';
    $depth = 0;
    $namespaceDepth = 0;
    $declarations = [];
    $references = [];
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_string($token)) {
            if ($token === '{') {
                $depth++;
            }
            if ($token === '}') {
                $depth--;
                if ($depth < $namespaceDepth) {
                    $namespace = '';
                    $namespaceDepth = $depth;
                }
            }
            continue;
        }
        [$kind, $text, $line] = $token;
        if ($kind === T_NAMESPACE) {
            $namespace = '';
            while (++$i < $count) {
                $next = $tokens[$i];
                if ($next === ';') {
                    $namespaceDepth = $depth;
                    break;
                }
                if ($next === '{') {
                    $depth++;
                    $namespaceDepth = $depth;
                    break;
                }
                if (is_array($next) && !in_array($next[0], $skip, true)) {
                    $namespace .= $next[1];
                }
            }
            $namespace = trim($namespace, '\\');
        } elseif (in_array($kind, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
                $j++;
            }
            if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $declarations[ltrim($namespace . '\\' . $tokens[$j][1], '\\')] = $line;
            }
        } elseif ($kind === T_USE && $depth === $namespaceDepth) {
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
                $j++;
            }
            if (($tokens[$j] ?? null) === '(') {
                continue;
            } // Closure capture, not a namespace import.
            $use = '';
            while ($j < $count && $tokens[$j] !== ';') {
                $part = $tokens[$j++];
                if (is_string($part)) {
                    $use .= $part;
                } elseif (!in_array($part[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $use .= $part[1];
                }
            }
            foreach (layoutImports($use) as $name) {
                $references[] = compact('name', 'line');
            }
            $i = $j;
        } elseif ($kind === T_NAME_FULLY_QUALIFIED) {
            $references[] = ['name' => ltrim($text, '\\'), 'line' => $line];
        }
    }
    return compact('declarations', 'references');
}

/** @return array<string,mixed> */
function checkSourceLayout(array $roots): array
{
    $repositories = [];
    $symbols = [];
    $findings = [];
    foreach ($roots as $input) {
        $root = realpath($input);
        if ($root === false || !is_dir($root) || realpath(trim(layoutGit($root, ['rev-parse', '--show-toplevel']))) !== $root) {
            throw new InvalidArgumentException('LAYOUT_EXACT_GIT_ROOT_REQUIRED');
        }
        if (isset($repositories[$root])) {
            throw new InvalidArgumentException('LAYOUT_DUPLICATE_ROOT');
        }
        $paths = array_values(array_filter(explode("\0", layoutGit($root, ['ls-files', '-z'])), 'strlen'));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $casePaths = [];
        $sources = [];
        $mappings = [];
        $history = 0;
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            $prefix = '';
            foreach ($parts as $part) {
                $prefix .= ($prefix === '' ? '' : '/') . $part;
                $casePaths[strtolower($prefix)][$prefix] = true;
            }
            if (layoutHistorical($path)) {
                $history++;
                continue;
            }
            if (array_intersect($parts, ['vendor', 'node_modules', '.local', '.git']) !== []) {
                continue;
            }
            if (!str_ends_with($path, '.php') && basename($path) !== 'composer.json') {
                continue;
            }
            $absolute = $root . '/' . $path;
            if (is_link($absolute) || !is_file($absolute)) {
                $findings[] = ['code' => 'SOURCE_NOT_REGULAR', 'root' => $root, 'path' => $path];
                continue;
            }
            $source = file_get_contents($absolute);
            if (!is_string($source)) {
                throw new RuntimeException('LAYOUT_SOURCE_UNREADABLE');
            }
            if (basename($path) === 'composer.json' && !layoutDevelopmentOnly($path)) {
                $json = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
                foreach (($json['autoload']['psr-4'] ?? []) as $namespace => $directories) {
                    foreach ((array) $directories as $directory) {
                        $base = (dirname($path) === '.' ? '' : dirname($path) . '/') . rtrim($directory, '/');
                        $mappings[] = ['namespace' => $namespace, 'base' => rtrim($base, '/')];
                    }
                }
            }
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $parsed = layoutSymbols($source);
            $parsed['production'] = !layoutDevelopmentOnly($path);
            $sources[$path] = $parsed;
            foreach ($parsed['declarations'] as $name => $line) {
                $symbols[strtolower($name)][$name][] = [$root, $path, $line];
            }
        }
        foreach ($casePaths as $variants) {
            if (count($variants) > 1) {
                $findings[] = ['code' => 'GIT_PATH_CASE_COLLISION', 'root' => $root, 'paths' => array_keys($variants)];
            }
        }
        $checked = 0;
        $explicitlyLoaded = 0;
        foreach ($sources as $path => $source) {
            if (!$source['production']) {
                continue;
            }
            // PSR-4 只约束 Composer 映射的源码树。独立升级运行时由入口显式 require，
            // 不因为它保留 app 命名空间就伪报应当搬进应用（这会破坏可信升级入口）。
            $insideMappedTree = false;
            foreach ($mappings as $mapping) {
                if (str_starts_with($path, $mapping['base'] . '/')) {
                    $insideMappedTree = true;
                }
            }
            if (!$insideMappedTree) {
                $explicitlyLoaded += count($source['declarations']);
                continue;
            }
            foreach ($source['declarations'] as $name => $line) {
                $expected = [];
                $longest = -1;
                foreach ($mappings as $mapping) {
                    if (!str_starts_with($name, $mapping['namespace'])) {
                        continue;
                    }
                    $length = strlen($mapping['namespace']);
                    if ($length < $longest) {
                        continue;
                    }
                    if ($length > $longest) {
                        $expected = [];
                        $longest = $length;
                    }
                    $expected[] = $mapping['base'] . '/' . str_replace('\\', '/', substr($name, $length)) . '.php';
                }
                if ($expected === []) {
                    continue;
                } // Explicit classmap / non-PSR file: not guessed into PSR-4.
                $checked++;
                if (!in_array($path, $expected, true)) {
                    $findings[] = ['code' => 'PSR4_DECLARATION_PATH_MISMATCH', 'root' => $root, 'path' => $path, 'line' => $line, 'type' => $name, 'expected' => array_values(array_unique($expected))];
                }
            }
        }
        $repositories[$root] = ['head' => trim(layoutGit($root, ['rev-parse', 'HEAD'])), 'tracked_paths' => count($paths), 'historical_paths' => $history, 'php_files_lexed' => count($sources), 'production_psr4_declarations_checked' => $checked, 'outside_psr4_declarations' => $explicitlyLoaded, 'sources' => $sources];
    }
    foreach ($repositories as $root => &$repository) {
        foreach ($repository['sources'] as $path => $source) {
            if (!$source['production']) {
                continue;
            }
            foreach ($source['references'] as $reference) {
                $known = $symbols[strtolower($reference['name'])] ?? [];
                if ($known !== [] && !isset($known[$reference['name']])) {
                    $findings[] = ['code' => 'TYPE_REFERENCE_CASE_MISMATCH', 'root' => $root, 'path' => $path, 'line' => $reference['line'], 'type' => $reference['name'], 'expected' => array_keys($known)];
                }
            }
        }
        unset($repository['sources']);
    }
    unset($repository);
    return ['status' => $findings === [] ? 'passed' : 'failed', 'scope' => 'Git path casing, production PSR-4 declarations and known PHP type spelling; no business/runtime qualification', 'repositories' => $repositories, 'findings' => $findings];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $roots = [];
        foreach (array_slice($argv, 1) as $argument) {
            if (!str_starts_with($argument, '--root=') || strlen($argument) === 7) {
                throw new InvalidArgumentException('Usage: php scripts/check-source-layout.php --root=<Git root> [--root=<other root>]');
            }
            $roots[] = substr($argument, 7);
        }
        if ($roots === []) {
            $roots[] = dirname(__DIR__);
        }
        $result = checkSourceLayout($roots);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        exit($result['status'] === 'passed' ? 0 : 1);
    } catch (Throwable $error) {
        fwrite(STDERR, 'SOURCE_LAYOUT_CHECK_FAILED: ' . $error->getMessage() . "\n");
        exit(2);
    }
}
