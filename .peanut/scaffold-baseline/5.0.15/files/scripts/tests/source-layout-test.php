<?php

declare(strict_types=1);

require dirname(__DIR__) . '/check-source-layout.php';
$base = realpath(sys_get_temp_dir());
$repo = dirname(__DIR__, 2);
if (!is_string($base) || !str_starts_with($base, $repo . '/.local/tmp/')) {
    throw new RuntimeException('SOURCE_LAYOUT_TEST_OWN_TMPDIR_REQUIRED');
}
$root = $base . '/source-layout-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$checks = 0;
$expect = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) {
        throw new RuntimeException($message);
    }
};
$write = static function (string $path, string $content) use ($root): void {
    if (!is_dir(dirname($root . '/' . $path))) {
        mkdir(dirname($root . '/' . $path), 0700, true);
    }
    file_put_contents($root . '/' . $path, $content);
};
try {
    layoutGit($root, ['init', '-q', '-b', 'dev']);
    $write('composer.json', json_encode(['autoload' => ['psr-4' => ['Probe\\' => 'src/', 'app\\' => 'app/']]], JSON_THROW_ON_ERROR));
    $write('src/Model/Record.php', "<?php\nnamespace Probe\\Model;\nfinal class Record {}\n");
    $write('app/services/Example.php', "<?php\nnamespace app\\services;\nuse Probe\\Model\\Record;\nclass Example {}\n");
    $write('scripts/Runtime.php', "<?php\nnamespace app\\tool;\nclass Runtime {}\n");
    layoutGit($root, ['add', '.']);
    layoutGit($root, ['-c', 'user.name=Layout Test', '-c', 'user.email=layout@example.invalid', 'commit', '-qm', 'synthetic layout']);
    $expect(checkSourceLayout([$root])['status'] === 'passed', 'VALID_HOST_AND_MODULE_LAYOUT_REJECTED');
    $write('app/services/Example.php', "<?php\nnamespace app\\services;\nuse Probe\\Model\\record;\nclass Example {}\n");
    $result = checkSourceLayout([$root]);
    $expect(array_column($result['findings'], 'code') === ['TYPE_REFERENCE_CASE_MISMATCH'], 'WRONG_IMPORT_CASE_NOT_REJECTED');
    $write('app/services/Example.php', "<?php\nnamespace app\\services;\nuse Probe\\Model\\Record;\nclass Example {}\n");
    $write('src/Model/Record.php', "<?php\nnamespace Probe\\Model;\nfinal class Record {}\nclass HiddenResult {}\n");
    $expect(in_array('PSR4_DECLARATION_PATH_MISMATCH', array_column(checkSourceLayout([$root])['findings'], 'code'), true), 'SECOND_TYPE_IN_WRONG_FILE_NOT_REJECTED');
    $write('src/Model/Record.php', "<?php\nnamespace Probe\\Model;\nfinal class Record {}\n");
    $write('src/Model/hidden.php', "<?php\nnamespace Probe\\Model;\nfinal class Hidden {}\n");
    layoutGit($root, ['add', 'src/Model/hidden.php']);
    $expect(in_array('PSR4_DECLARATION_PATH_MISMATCH', array_column(checkSourceLayout([$root])['findings'], 'code'), true), 'CASE_INSENSITIVE_HOST_MASKED_FILENAME_ERROR');
    $expect(layoutImports('Probe\\Model\\{Record, Hidden as Value}') === ['Probe\\Model\\Record', 'Probe\\Model\\Hidden'], 'GROUPED_IMPORT_PARSING_FAILED');
    $expect(layoutImports('function Probe\\helper') === [], 'FUNCTION_IMPORT_MISCLASSIFIED');
    $symbols = layoutSymbols("<?php namespace Probe; class A { use SomeTrait; } function f() { \$g=function() use (\$x) {}; } // class Fake {}\n");
    $expect(array_keys($symbols['declarations']) === ['Probe\\A'] && $symbols['references'] === [], 'COMMENTS_TRAITS_OR_CLOSURES_MISCLASSIFIED');
    $expect(layoutHistorical('scaffold/releases/4.0.0/source.php') && !layoutHistorical('server/app/Release.php'), 'HISTORICAL_SCOPE_CHANGED');
    echo 'SOURCE-LAYOUT-CHECKER-001 checks=' . $checks . " passed\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
