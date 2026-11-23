<?php

declare(strict_types=1);

use app\platform\infrastructure\module\ModuleArtifactWriter;

$codeRoot = dirname(__DIR__, 3);
$root = realpath($argv[1] ?? (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: $codeRoot));
if ($root === false) { throw new RuntimeException('Prepared application root is unavailable'); }
require $root . '/server/vendor/autoload.php';

/**
 * Eight-Module Host composition qualification.
 * Optional Article/Member/OAuth/Payment/Notification/Workflow/ImportExport
 * are tested in the 22-Module application, not reinstalled into Code.
 * This is a static source/manifest check; real tenant HTTP/MySQL and revoked
 * role tests retain their own executable qualifications.
 */
function qualificationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expected = [
    'peanut.content-tools', 'peanut.file', 'peanut.identity',
    'peanut.integration', 'peanut.ops', 'peanut.reference-codes',
    'peanut.settings', 'peanut.task',
];
$lock = json_decode((string) file_get_contents($root . '/modules.lock'), true, 512, JSON_THROW_ON_ERROR);
$applicationComposer = json_decode((string) file_get_contents($root . '/server/composer.json'), true, 512, JSON_THROW_ON_ERROR);
qualificationExpect(($lock['schema_version'] ?? null) === 1 && is_array($lock['modules'] ?? null),
    'selected Module lock must be a native v1 lock');

$actual = array_column($lock['modules'], 'key');
sort($actual, SORT_STRING);
qualificationExpect($actual === $expected, 'Prepared Host has an incomplete or unexpected Module graph');
(new ModuleArtifactWriter($root . '/server'))->checkLock();

$owners = [];
foreach ($lock['modules'] as $module) {
    $key = $module['key'];
    $path = (string) ($module['manifest'] ?? '');
    qualificationExpect(
        preg_match('#^server/app/modules/peanut/[a-z_]+/module\.json$#D', $path) === 1
            && is_file($root . '/' . $path),
        'selected Module declaration lacks a normal source owner: ' . $key,
    );
    $source = json_decode((string) file_get_contents($root . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
    $phpPackage = $source['php_package'] ?? '';
    $composerPath = dirname($root . '/' . $path) . '/composer.json';
    $composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
    qualificationExpect(
        ($source['key'] ?? null) === $key
            && ($source['version'] ?? null) === $module['version']
            && ($composer['name'] ?? null) === $phpPackage
            && ($composer['version'] ?? null) === $module['version']
            && ($applicationComposer['require'][$phpPackage] ?? null) === $module['version'],
        'Module graph and locked Composer identity disagree: ' . $key,
    );
    $tenant = $source['tenant'] ?? [];
    $protected = ($source['lifecycle']['protected'] ?? false) === true;
    qualificationExpect(
        ($tenant['enableable'] ?? null) === true
            ? (($tenant['disable_behavior'] ?? null) === 'reject_new_operations')
            : (($tenant['enableable'] ?? null) === false && $protected),
        'a selected Module is not an explicit protected foundation or fail-closed tenant feature: ' . $key,
    );
    foreach ($source['dependencies'] ?? [] as $dependency) {
        qualificationExpect(in_array($dependency['module_key'] ?? null, $expected, true),
            'Locked Module requires an unselected dependency: ' . $key);
    }
    foreach ($tenant['requires'] ?? [] as $requiredKey) {
        qualificationExpect(in_array($requiredKey, $expected, true),
            'Tenant Module has an unselected prerequisite: ' . $key);
    }
    foreach ($source['database']['owned_tables'] ?? [] as $table) {
        qualificationExpect(
            !isset($owners[$table]) || $owners[$table] === $key,
            'two selected Modules declare the same table: ' . $table,
        );
        $owners[$table] = $key;
    }
    qualificationExpect(is_string($source['backend']['provider'] ?? null)
        && trim($source['backend']['provider']) !== '', 'Module provider is missing: ' . $key);
}
qualificationExpect(
    is_file($codeRoot . '/server/tests/Multitenancy/AdminApiPermissionBoundaryTest.php')
        && is_file($codeRoot . '/server/tests/Modules/Official/ExecutionRootLifecycleTest.php')
        && is_file($codeRoot . '/server/tests/Unit/IntegrationBootstrapAuthorizationTest.php'),
    'Code no longer carries its own authorization, execution or Integration host-boundary tests',
);

echo 'CODE-SELECTED-MODULE-QUALIFICATION-001 passed; selected=8; tenant/manifest/dependency/source checks; actual HTTP/MySQL handled by native dynamic suites' . PHP_EOL;
