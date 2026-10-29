#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 验证生成应用自己的环境、租约、原生依赖和未安装状态；只读数据库。
 * 不生成环境或 services.php，不执行安装、迁移、清库或共享资源操作。
 * 调用前设置该应用的 PEANUT_SERVER_ENV_FILE，使用项目已批准的临时资源。
 */
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(application-root|source-commit)=(.+)$/D', $argument, $match) !== 1
        || isset($options[$match[1]])) {
        fwrite(STDERR, "Usage: php scripts/tests/generated-application-preinstall.php --application-root=/absolute/path --source-commit=<40-hex>\n");
        exit(64);
    }
    $options[$match[1]] = $match[2];
}
if (count($options) !== 2 || !isset($options['application-root'], $options['source-commit'])
    || preg_match('/^[0-9a-f]{40}$/D', $options['source-commit']) !== 1) {
    fwrite(STDERR, "Both exact application root and source commit are required.\n");
    exit(64);
}
$checks = 0;
$expect = static function (bool $condition, string $code) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($code);
    }
};
try {
    $root = realpath($options['application-root']);
    $expect(is_string($root) && $root === $options['application-root'] && is_dir($root), 'PREINSTALL_APPLICATION_ROOT_INVALID');
    $document = json_decode((string) file_get_contents($root . '/.peanut/application-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $expect(($document['template']['source_commit'] ?? null) === $options['source-commit']
        && ($document['generation_source']['commit'] ?? null) === $options['source-commit'], 'PREINSTALL_SOURCE_IDENTITY_MISMATCH');
    $lockBefore = hash_file('sha256', $root . '/server/composer.lock');
    $services = $root . '/server/vendor/services.php';
    $expect(is_file($services) && !is_link($services), 'PREINSTALL_SERVICE_DISCOVERY_REQUIRED');
    require $root . '/server/database/environment-guard.php';
    $config = guardedDatabaseConfig();
    $expect($config['deployment_target'] === 'development-test' && $config['consumer'] === 'host', 'PREINSTALL_EPHEMERAL_RESOURCE_REQUIRED');
    $resource = registeredDatabase(projectResourceRegistry(), $config['resource_id']);
    $expect(
        ($resource['lifecycle'] ?? null) === 'ephemeral' && ($resource['application_runtime'] ?? null) === false,
        'PREINSTALL_EPHEMERAL_RESOURCE_REQUIRED',
    );
    // 租约/环境校验先于首次数据库连接；非空库在框架启动前直接拒绝。
    $pdo = guardedConnection($config);
    $identity = $pdo->query('SELECT VERSION() AS version, DATABASE() AS database_name, @@server_uuid AS server_uuid')->fetch();
    $before = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    $expect($before === 0, 'PREINSTALL_EMPTY_DATABASE_REQUIRED');
    $app = (new think\App($root . '/server/'))->initialize();
    $status = $app->make(app\common\services\installation\InstallationExecutionHost::class)->status();
    $expect(($status['state'] ?? null) === 'uninstalled' && ($status['code'] ?? null) === 'INSTALL_READY', 'PREINSTALL_STATE_NOT_READY');
    $expect(($status['preflight']['status'] ?? null) === 'ready', 'PREINSTALL_PREFLIGHT_NOT_READY');
    $expect(($status['deployment_mode'] ?? null) === ($document['application']['edition'] ?? null), 'PREINSTALL_EDITION_MISMATCH');
    $expect(count($status['official_modules'] ?? []) > 0, 'PREINSTALL_OFFICIAL_MODULES_UNAVAILABLE');
    foreach ([
        PeanutAdmin\Kernel\Auth\TenantContext::class,
        app\common\services\installation\InstallationExecutionHost::class,
        think\migration\command\migrate\Run::class,
    ] as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        $actual = is_string($file) ? realpath($file) : false;
        $expect(is_string($actual) && str_starts_with($actual, $root . '/server/'), 'PREINSTALL_EXTERNAL_CLASS_SOURCE');
    }
    $after = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    $expect($after === $before, 'PREINSTALL_UNEXPECTED_SCHEMA_WRITE');
    $expect($lockBefore === hash_file('sha256', $root . '/server/composer.lock'), 'PREINSTALL_NATIVE_LOCK_CHANGED');
    echo json_encode([
        'status' => 'passed', 'checks' => $checks, 'source_commit' => $options['source-commit'],
        'edition' => $status['deployment_mode'], 'installation_state' => $status['state'],
        'module_count' => count($status['official_modules']), 'database' => $identity,
        'resource_id' => $config['resource_id'], 'tables_before' => $before, 'tables_after' => $after,
        'composer_lock_sha256' => $lockBefore, 'database_installed' => false, 'product_qualified' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // 不输出可能包含连接参数、凭据或调用参数的堆栈。
    $message = $error->getMessage();
    $safe = preg_match('/^(?:PREINSTALL_[A-Z_]+|BACKEND_ENVIRONMENT_[A-Z_]+(?::[A-Z_]+)?)$/D', $message) === 1
        ? $message : 'PREINSTALL_RUNTIME_REJECTED';
    fwrite(STDERR, json_encode(['status' => 'failed', 'checks' => $checks, 'code' => $safe,
        'error_class' => $error::class], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
