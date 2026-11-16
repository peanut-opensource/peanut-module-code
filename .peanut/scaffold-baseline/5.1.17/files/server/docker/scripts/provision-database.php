#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || count($argv) !== 3
    || !str_starts_with($argv[1], '--server-root=/')
    || !str_starts_with($argv[2], '--docker-env=/')) {
    fwrite(STDERR, "Usage: php provision-database.php --server-root=/absolute/server --docker-env=/absolute/server/docker/.env\n");
    exit(64);
}

try {
    $serverRoot = substr($argv[1], strlen('--server-root='));
    $dockerEnvPath = substr($argv[2], strlen('--docker-env='));
    if (realpath($serverRoot) !== $serverRoot || !is_dir($serverRoot) || is_link($serverRoot)) {
        throw new RuntimeException('server root is invalid');
    }
    $envPath = $serverRoot . '/.env';
    if (!is_file($envPath) || is_link($envPath)) {
        throw new RuntimeException('server/.env is unavailable');
    }
    $envStat = lstat($envPath);
    if (!is_array($envStat) || ($envStat['nlink'] ?? 0) !== 1 || ($envStat['mode'] & 0777) !== 0600) {
        throw new RuntimeException('server/.env must be one regular mode-0600 file');
    }
    $environment = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if (!is_array($environment)) {
        throw new RuntimeException('server/.env is invalid');
    }
    $configurationPath = $serverRoot . '/private/resources/configuration.json';
    if (!is_file($configurationPath) || is_link($configurationPath)) {
        throw new RuntimeException('instance configuration receipt is unavailable');
    }
    $configuration = json_decode((string) file_get_contents($configurationPath), true, 512, JSON_THROW_ON_ERROR);
    if (($configuration['database_source'] ?? null) !== 'bundled'
        || ($configuration['database_name'] ?? null) !== ($environment['DB_NAME'] ?? null)) {
        throw new RuntimeException('only the bundled database source permits provisioning');
    }

    if ($dockerEnvPath !== $serverRoot . '/docker/.env' || !is_file($dockerEnvPath) || is_link($dockerEnvPath)) {
        throw new RuntimeException('Docker environment is unavailable');
    }
    $dockerEnvStat = lstat($dockerEnvPath);
    if (!is_array($dockerEnvStat) || ($dockerEnvStat['nlink'] ?? 0) !== 1 || ($dockerEnvStat['mode'] & 0777) !== 0600) {
        throw new RuntimeException('Docker environment must be one regular mode-0600 file');
    }
    $dockerEnvironment = parse_ini_file($dockerEnvPath, false, INI_SCANNER_RAW);
    $rootPassword = is_array($dockerEnvironment) ? ($dockerEnvironment['MYSQL_ROOT_PASSWORD'] ?? null) : null;
    if (!is_string($rootPassword) || $rootPassword === '' || preg_match('/[\r\n\x00]/', $rootPassword) === 1) {
        throw new RuntimeException('Docker environment is missing MYSQL_ROOT_PASSWORD');
    }

    foreach ([
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
    ] as $required) {
        if (!is_string($environment[$required] ?? null) || trim($environment[$required]) === '') {
            throw new RuntimeException('server/.env is missing ' . $required);
        }
    }
    if ($environment['DB_HOST'] !== 'mysql' || $environment['DB_PORT'] !== '3306') {
        throw new RuntimeException('browser bootstrap only provisions the bundled MySQL service');
    }
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $environment['DB_NAME']) !== 1
        || preg_match('/^[A-Za-z0-9_]{1,32}$/D', $environment['DB_USER']) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $environment['DB_PASS']) !== 1) {
        throw new RuntimeException('database bootstrap identity is invalid');
    }

    $receiptPath = $serverRoot . '/private/resources/database-provisioned.json';
    if (file_exists($receiptPath) || is_link($receiptPath)) {
        if (!is_file($receiptPath) || is_link($receiptPath)) {
            throw new RuntimeException('database provisioning receipt is unsafe');
        }
        $receipt = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
        if (($receipt['protocol'] ?? null) !== 'peanut.database-provisioning.v1'
            || ($receipt['database'] ?? null) !== $environment['DB_NAME']) {
            throw new RuntimeException('database provisioning receipt differs from current instance');
        }
        echo json_encode(['status' => 'already_provisioned', 'database' => $environment['DB_NAME']], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }

    $pdo = new PDO(
        'mysql:host=mysql;port=3306;charset=utf8mb4',
        'root',
        $rootPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
    );
    $statement->execute([$environment['DB_NAME']]);
    if ((int) $statement->fetchColumn() !== 0) {
        throw new RuntimeException('target database is not empty; refusing first provisioning');
    }

    $database = $environment['DB_NAME'];
    $user = $environment['DB_USER'];
    $password = $environment['DB_PASS'];
    $tick = chr(96);
    $quotedDatabase = $tick . $database . $tick;
    $quotedUser = "'" . $user . "'@'%'";
    $quotedPassword = "'" . $password . "'";
    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $quotedDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('CREATE USER IF NOT EXISTS ' . $quotedUser . ' IDENTIFIED BY ' . $quotedPassword);
    $pdo->exec('ALTER USER ' . $quotedUser . ' IDENTIFIED BY ' . $quotedPassword);
    $pdo->exec('GRANT ALL PRIVILEGES ON ' . $quotedDatabase . '.* TO ' . $quotedUser);
    $pdo->exec('FLUSH PRIVILEGES');

    $directory = dirname($receiptPath);
    $temporary = $directory . '/.database-provisioned-' . bin2hex(random_bytes(8));
    $receipt = [
        'schema_version' => 1,
        'protocol' => 'peanut.database-provisioning.v1',
        'state' => 'provisioned',
        'database' => $database,
        'provisioned_at' => gmdate(DATE_ATOM),
    ];
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
        || !chmod($temporary, 0600)
        || !rename($temporary, $receiptPath)) {
        @unlink($temporary);
        throw new RuntimeException('cannot publish database provisioning receipt');
    }

    echo json_encode(['status' => 'provisioned', 'database' => $database], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'database-provisioning: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
