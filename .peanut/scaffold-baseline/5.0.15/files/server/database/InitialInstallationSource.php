<?php

declare(strict_types=1);

/** First-install inputs; execution and migration history remain owned by ThinkPHP. */
final class InitialInstallationSource
{
    /** Initial sources belong to the instance snapshot, not a later template's baseline. */
    public static function migrationFiles(string $serverRoot): array
    {
        $serverRoot = rtrim($serverRoot, DIRECTORY_SEPARATOR);
        $projectRoot = dirname($serverRoot);
        if (\app\common\value\installation\ApplicationSourceIdentity::hasRuntime($serverRoot)) {
            \app\common\value\installation\ApplicationSourceIdentity::load($serverRoot);
            $manifestPath = $serverRoot . '/.peanut/application-manifest.json';
        } elseif (file_exists($serverRoot . '/.peanut/release-identity.json') || is_link($serverRoot . '/.peanut/release-identity.json')) {
            $identity = \app\common\value\installation\ServerReleaseIdentity::resolve($serverRoot);
            return array_map(
                static fn(array $file): string => $serverRoot . '/' . substr($file['path'], 7),
                array_values(array_filter($identity->document()['files'], static fn(array $file): bool =>
                    preg_match('#^server/database/installation/[^/]+\.php$#D', $file['path']) === 1)),
            );
        } elseif (file_exists($projectRoot . '/.peanut/application-manifest.json') || is_link($projectRoot . '/.peanut/application-manifest.json')) {
            \app\common\value\installation\ApplicationSourceIdentity::loadGeneratedApp($serverRoot);
            $manifestPath = $projectRoot . '/.peanut/application-manifest.json';
        } elseif (self::sourceDevelopmentMode($serverRoot)) {
            $files = glob($serverRoot . '/database/installation/*.php') ?: [];
            if ($files === []) { throw new RuntimeException('INSTALL_INITIAL_SOURCE_MISSING'); }
            return $files;
        } else {
            throw new RuntimeException('INSTALL_INITIAL_SOURCE_IDENTITY_REQUIRED');
        }

        $manifest = json_decode(self::read($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $snapshot = array_key_exists('installation_migrations', $manifest) ? $manifest['installation_migrations'] : [];
        if (!is_array($snapshot) || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('INSTALL_INITIAL_SNAPSHOT_INVALID');
        }
        $files = array_column($manifest['files'], null, 'path');
        $migrations = [];
        foreach ($snapshot as $path => $digest) {
            $file = $files[$path] ?? null;
            if (!is_string($path) || preg_match('#^server/database/(?:installation|migrations|schema|seeds)/[^/]+$#D', $path) !== 1
                || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1 || !is_array($file)
                || (in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)
                    ? ($file['baseline_sha256'] ?? null) !== $digest
                    : (($file['classification'] ?? null) !== 'app-owned' || ($file['sha256'] ?? null) !== $digest
                        || ($file['owner'] ?? null) !== 'application' || ($file['source'] ?? null) !== $path))) {
                throw new RuntimeException('INSTALL_INITIAL_SNAPSHOT_INVALID');
            }
            $absolute = $serverRoot . '/' . substr($path, 7);
            if (!is_file($absolute) || realpath($absolute) !== $absolute || hash_file('sha256', $absolute) !== $digest) {
                throw new RuntimeException('INSTALL_INITIAL_SOURCE_CHANGED: ' . $path);
            }
            if (str_starts_with($path, 'server/database/installation/') && str_ends_with($path, '.php')) {
                $migrations[] = $absolute;
            }
        }
        return $migrations;
    }

    public static function sourceDevelopmentMode(string $serverRoot): bool
    {
        $root = dirname($serverRoot);
        return env('PEANUT_INSTALLATION_SOURCE_MODE', false) === 'development'
            && file_exists($root . '/.git') && !is_link($root . '/.git')
            && (is_file($root . '/.peanut/application-manifest.json')
                || (is_file($root . '/scaffold/application-template-inventory.json')
                    && is_file($root . '/scaffold/edition-profiles.json')))
            && is_file($root . '/release-versions.json');
    }

    public const MODULES = [
        'peanut.identity', 'peanut.file', 'peanut.settings', 'peanut.reference-codes',
        'peanut.task', 'peanut.ops', 'peanut.content-tools', 'peanut.integration',
    ];

    // These legacy Host tables have no Module owner. Module tables come only from module.json.
    private const HOST_TABLES = [
        'pa_config', 'pa_customer_service_setting', 'pa_decorate_page', 'pa_decorate_tabbar',
        'pa_decorate_tabbar_setting', 'pa_generator_column', 'pa_generator_download',
        'pa_generator_table', 'pa_hot_search', 'pa_operation_log', 'pa_plugin_installation',
        'pa_plugin_module', 'pa_provider_qualification_evidence', 'pa_tenant_idempotency_record',
        'pa_transaction_setting',
    ];

    /** @param list<string> $modules @return array<string,string> */
    public static function project(string $serverRoot, array $modules): array
    {
        $tables = array_fill_keys(self::tables($serverRoot, $modules), true);
        $schema = self::read($serverRoot . '/database/schema/baseline-schema.sql');
        preg_match_all('/^CREATE TABLE `([^`]+)` \(\n.*?^\) ENGINE=[^\r\n]+;$/ms', $schema, $definitions, PREG_SET_ORDER);
        $selected = [];
        foreach ($definitions as $definition) {
            if (in_array($definition[1], self::HOST_TABLES, true)) {
                preg_match_all('/\bREFERENCES\s+`([^`]+)`/i', $definition[0], $references);
                foreach ($references[1] as $referencedTable) {
                    if (!isset($tables[$referencedTable])) {
                        throw new RuntimeException(
                            'INSTALL_INITIAL_DEPENDENCY_MISSING: ' . $definition[1] . ' requires ' . $referencedTable,
                        );
                    }
                }
                $selected[] = $definition[0];
            }
        }
        if ($selected === []) {
            throw new RuntimeException('INSTALL_INITIAL_SCHEMA_MISSING');
        }

        $data = self::read($serverRoot . '/database/schema/baseline-data.sql');
        // Current defaults are projected to the selected Modules; their owners
        // synchronize permission and menu catalogs after dynamic identity setup.
        preg_match_all('/^INSERT\b.*?;(?=\r?\n|$)/ms', $data, $statements);
        $defaults = [];
        foreach ($statements[0] as $statement) {
            if (preg_match('/^INSERT(?: IGNORE)? INTO `([^`]+)`/', $statement, $target) === 1
                && isset($tables[$target[1]])
                && in_array($target[1], ['pa_system_dict_type', 'pa_system_dict_data', 'pa_tenant_setting'], true)) {
                $defaults[] = $statement;
            }
        }
        return [
            'schema/baseline-schema.sql' => implode("\n\n", $selected) . "\n",
            'schema/baseline-data.sql' => implode("\n\n", $defaults) . "\n",
            'migrations/20261101020000_initial_scaffold_baseline.php' => self::read(
                $serverRoot . '/database/installation/20261101020000_initial_scaffold_baseline.php',
            ),
        ];
    }

    /** @param list<string> $modules @return list<string> */
    public static function tables(string $serverRoot, array $modules): array
    {
        $tables = array_fill_keys(self::HOST_TABLES, true);
        foreach ($modules as $key) {
            $path = $serverRoot . '/app/modules/' . str_replace(['.', '-'], ['/', '_'], $key) . '/module.json';
            $manifest = json_decode(self::read($path), true, 128, JSON_THROW_ON_ERROR);
            foreach ($manifest['database']['owned_tables'] ?? [] as $table) {
                $tables[$table] = true;
            }
        }
        return array_keys($tables);
    }

    /** @return list<string> */
    public static function installedModules(string $serverRoot): array
    {
        foreach (['installed.json', 'executing.json'] as $name) {
            $path = $serverRoot . '/private/installation/' . $name;
            if (is_file($path)) {
                $marker = json_decode(self::read($path), true, 128, JSON_THROW_ON_ERROR);
                if (is_array($marker['official_modules'] ?? null)) {
                    return $marker['official_modules'];
                }
            }
        }
        return self::MODULES;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('INSTALL_INITIAL_SOURCE_MISSING: ' . $path);
        }
        return $content;
    }
}
