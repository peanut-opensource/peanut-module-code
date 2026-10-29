<?php

declare(strict_types=1);

/** Run one native fixture case in a fresh process. The Python driver pins all source roots. */
$root = getenv('TPQ_SOURCE_ROOT');
$core = getenv('TPQ_CORE_SOURCE_ROOT');
$vendor = getenv('TPQ_VENDOR_ROOT');
$scenario = $argv[1] ?? '';
if (!$root || !$core || !$vendor || !in_array($scenario, ['baseline', 'missing-parent', 'foreign-parent', 'foreign-link',
    'page-baseline', 'page-missing-parent', 'page-foreign-parent', 'page-foreign-set',
    'page-corrupt-outside-page', 'page-callback-failure'], true)) {
    throw new RuntimeException('TPQ_PARENT_RUNTIME_INPUT_INVALID');
}
// Use Composer's installed third-party maps without importing the vendor
// checkout's stale application/test autoload files. Source classes come only
// from the two explicit immutable snapshots below.
require $vendor . '/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader();
$insideVendor = static fn(string $path): bool => is_string(realpath($path))
    && str_starts_with(realpath($path), $vendor . DIRECTORY_SEPARATOR);
foreach (require $vendor . '/composer/autoload_psr4.php' as $prefix => $paths) {
    $paths = array_values(array_filter($paths, $insideVendor));
    if ($paths !== [] && !str_starts_with($prefix, 'PeanutAdmin\\') && $prefix !== 'app\\') {
        $loader->setPsr4($prefix, $paths);
    }
}
$loader->addClassMap(array_filter(require $vendor . '/composer/autoload_classmap.php', $insideVendor));
foreach ([$root . '/server', $core] as $sourceRoot) {
    $manifest = json_decode(file_get_contents($sourceRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest['autoload']['psr-4'] as $prefix => $paths) {
        $loader->setPsr4($prefix, array_map(static fn(string $path): string => $sourceRoot . '/' . $path, (array) $paths));
    }
}
$loader->register(true);
foreach (require $vendor . '/composer/autoload_files.php' as $file) {
    if ($insideVendor($file)) {
        require_once $file;
    }
}
require $root . '/server/tests/Support/ThinkPhpTestConnection.php';
$expected = json_decode(file_get_contents($root . '/runtime-source-proof.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($expected['classes'] as $class => $source) {
    $loaded = (new ReflectionClass($class))->getFileName();
    if (realpath($loaded) !== realpath($source) || hash_file('sha256', $loaded) !== $expected['sha256'][$source]) {
        throw new RuntimeException('TPQ_PARENT_RUNTIME_SOURCE_MISMATCH: ' . $class);
    }
}
foreach ($expected['packages'] as $package => $reference) {
    if (Composer\InstalledVersions::getReference($package) !== $reference) {
        throw new RuntimeException('TPQ_PARENT_RUNTIME_DEPENDENCY_MISMATCH: ' . $package);
    }
}
// This reviewed source contains only test declarations. Its setUp uses sqlite::memory:.
require $root . '/server/tests/Unit/ReferenceCodeSnapshotBoundaryTest.php';
$fixture = new tests\Unit\ReferenceCodeSnapshotBoundaryTest('testEmptySnapshotAndForeignCodeReturnNoForeignRecords');
(new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
$value = static fn(string $property): mixed => (new ReflectionProperty($fixture, $property))->getValue($fixture);
$database = $value('database');
if (!$database instanceof PDO || $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
    throw new RuntimeException('TPQ_PARENT_RUNTIME_REQUIRES_MEMORY_FIXTURE');
}
$store = $value('store');
$definition = $value('definition');
$context = $value('context');
$asOf = $value('asOf');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('TPQ_PARENT_RUNTIME_ASSERTION: ' . $message);
    }
};
if (str_starts_with($scenario, 'page-')) {
    $query = new PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeQuery($store);
    if (in_array($scenario, ['page-missing-parent', 'page-foreign-parent', 'page-foreign-set'], true)) {
        $database->exec(match ($scenario) {
            'page-missing-parent' => 'DELETE FROM pa_reference_code_entry WHERE id=10',
            'page-foreign-parent' => 'UPDATE pa_reference_code_entry SET tenant_id=202 WHERE id=10',
            'page-foreign-set' => 'UPDATE pa_reference_code_entry SET set_id=999 WHERE id=10',
        });
        $assert((int) $database->query('SELECT COUNT(*) FROM pa_reference_code_entry_version WHERE entry_id=10')->fetchColumn() === 2, 'negative children remain present');
        $page = $query->list($definition, $context, $asOf, page: 1, pageSize: 1);
        $assert($page['total'] === 0 && $page['items'] === [], 'page cannot expose orphan, foreign Tenant or foreign set children');
    } elseif ($scenario === 'page-corrupt-outside-page') {
        $database->exec("INSERT INTO pa_reference_code_entry SELECT 30,tenant_id,set_id,'z-hidden-code',lifecycle,revision,created_by_member_id,updated_by_member_id,retired_at,created_at,updated_at FROM pa_reference_code_entry WHERE id=10;");
        $database->exec("INSERT INTO pa_reference_code_entry_version SELECT id+30,30,revision,label,'not-json','inactive',sort_order,effective_at,expires_at,changed_by_member_id,created_at FROM pa_reference_code_entry_version WHERE entry_id=10;");
        $rejected = false;
        try {
            $query->list($definition, $context, $asOf, effectiveStatus: 'active', page: 1, pageSize: 1);
        } catch (PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeException) {
            $rejected = true;
        }
        $assert($rejected, 'Query callback must reject corrupt history outside page/filter');
    } elseif ($scenario === 'page-callback-failure') {
        $failure = new DomainException('TPQ_PAGE_CALLBACK_SENTINEL');
        $seen = false;
        $propagated = false;
        try {
            $store->pageSnapshot(
                $definition,
                $context,
                $asOf,
                'all',
                false,
                1,
                1,
                static function (array $raw, DateTimeImmutable $instant) use ($failure, &$seen, $assert, $asOf): void {
                    $seen = true;
                    $assert($instant == $asOf && (int) $raw['entry']['tenant_id'] === 101, 'scoped callback with fixed time');
                    throw $failure;
                },
            );
        } catch (DomainException $error) {
            $assert($error === $failure, 'original callback failure must propagate');
            $propagated = true;
        }
        $assert($seen && $propagated, 'callback must execute and its failure must propagate');
    } else {
        $page = $query->list($definition, $context, $asOf, page: 1, pageSize: 1);
        $assert($page['total'] === 1 && count($page['items']) === 1, 'one scoped page and total');
        $assert($page['items'][0]->code === 'sample-code' && $page['items'][0]->effective['label'] === 'Earlier', 'effective version preserved');
    }
} elseif ($scenario === 'baseline') {
    $snapshot = $store->snapshot($definition, $context, 'sample-code', $asOf);
    $assert(count($snapshot['entries']) === 1, 'one scoped parent');
    $assert(array_column($snapshot['entries'][0]['versions'], 'entry_id') === [10, 10], 'only matching children');
} elseif ($scenario === 'foreign-link') {
    $database->exec('UPDATE pa_reference_code_entry_version SET entry_id=20 WHERE id=11');
    $query = new PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeQuery($store);
    $rejected = false;
    try {
        $query->get($definition, $context, 'sample-code', $asOf);
    } catch (PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeException) {
        $rejected = true;
    }
    $assert($rejected, 'broken parent-version history must reject, not include foreign records');
} else {
    $database->exec($scenario === 'missing-parent'
        ? 'DELETE FROM pa_reference_code_entry WHERE id=10'
        : 'UPDATE pa_reference_code_entry SET tenant_id=202 WHERE id=10');
    $assert((int) $database->query('SELECT COUNT(*) FROM pa_reference_code_entry_version WHERE entry_id=10')->fetchColumn() === 2, 'orphan/foreign-parent children retained for negative test');
    $snapshot = $store->snapshot($definition, $context, 'sample-code', $asOf);
    $assert($snapshot['entries'] === [], 'missing or foreign parent must not expose children');
}
$assert(!$database->inTransaction(), 'transaction restored');
echo json_encode(['scenario' => $scenario, 'result' => 'passed', 'database' => 'isolated sqlite::memory:',
    'business_scan' => 'not_run', 'source_classes' => array_keys($expected['classes'])], JSON_THROW_ON_ERROR) . PHP_EOL;
