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

    /** Product selection owns roots only; Module manifests own their dependency edges. */
    public static function composition(string $serverRoot): array
    {
        $composition = json_decode(self::read($serverRoot . '/config/module_composition.json'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($composition) || ($composition['schema_version'] ?? null) !== 1
            || ($composition['protocol'] ?? null) !== 'peanut.application-module-composition.v1'
            || !is_string($composition['version'] ?? null)
            || preg_match('/^\d+\.\d+\.\d+$/D', $composition['version']) !== 1) {
            throw new RuntimeException('INSTALL_MODULE_COMPOSITION_INVALID');
        }
        $seen = [];
        foreach (['mandatory', 'default', 'optional'] as $selection) {
            $keys = $composition[$selection] ?? null;
            if (!is_array($keys) || !array_is_list($keys) || ($selection === 'mandatory' && $keys === [])) {
                throw new RuntimeException('INSTALL_MODULE_COMPOSITION_INVALID');
            }
            foreach ($keys as $key) {
                if (!is_string($key) || preg_match('/^peanut\.[a-z][a-z0-9-]*$/D', $key) !== 1 || isset($seen[$key])) {
                    throw new RuntimeException('INSTALL_MODULE_COMPOSITION_INVALID');
                }
                $seen[$key] = true;
            }
        }
        return $composition;
    }

    /** @return list<string> Dependency-first selection; this does not install or enable Modules. */
    public static function modules(string $serverRoot, bool $includeDefaults = true): array
    {
        $composition = self::composition($serverRoot);
        $pending = [...$composition['mandatory'], ...($includeDefaults ? $composition['default'] : [])];
        $definitions = [];
        $layout = \app\common\infrastructure\module\ModuleHostLayoutFactory::pathLayout();
        $loader = new \PeanutAdmin\Kernel\Module\ManifestLoader();
        while ($pending !== []) {
            $key = array_pop($pending);
            if (isset($definitions[$key])) {
                continue;
            }
            $relative = $layout->backendRelativePath(\PeanutAdmin\Kernel\Module\ModuleKey::fromString($key));
            $root = $serverRoot . '/' . substr($relative, strlen('server/'));
            if (!is_file($root . '/module.json')) {
                throw new RuntimeException('INSTALL_MODULE_SOURCE_MISSING: ' . $key);
            }
            $manifest = $loader->load($root)->data;
            if ($manifest['key'] !== $key) {
                throw new RuntimeException('INSTALL_MODULE_COMPOSITION_IDENTITY_MISMATCH: ' . $key);
            }
            $definitions[$key] = [
                'version' => $manifest['version'],
                'dependencies' => $manifest['dependencies'] ?? [],
            ];
            foreach ($definitions[$key]['dependencies'] as $dependency) {
                $pending[] = $dependency['module_key'];
            }
        }
        return (new \app\common\module\PackageCheck(dirname($serverRoot)))->dependencyOrder($definitions, []);
    }

    // These legacy Host tables have no Module owner. Module tables come only from module.json.
    private const HOST_TABLES = [
        'pa_config', 'pa_customer_service_setting', 'pa_decorate_page', 'pa_decorate_tabbar',
        'pa_decorate_tabbar_setting', 'pa_generator_column', 'pa_generator_download',
        'pa_generator_table', 'pa_hot_search', 'pa_operation_log',
        'pa_provider_qualification_evidence', 'pa_tenant_idempotency_record',
    ];

    /** @param list<string> $modules @return array<string,string> */
    public static function project(string $serverRoot, array $modules): array
    {
        return self::projectSql($serverRoot, array_fill_keys(self::tables($serverRoot, $modules), true));
    }

    /** @return array<string,string> */
    public static function projectTemplate(string $serverRoot): array
    {
        return self::projectSql($serverRoot, null);
    }

    /** @param array<string,true>|null $tables @return array<string,string> */
    private static function projectSql(string $serverRoot, ?array $tables): array
    {
        $schema = self::read($serverRoot . '/database/schema/baseline-schema.sql');
        preg_match_all('/^CREATE TABLE `([^`]+)` \(\n.*?^\) ENGINE=[^\r\n]+;$/ms', $schema, $definitions, PREG_SET_ORDER);
        $selected = [];
        foreach ($definitions as $definition) {
            if (in_array($definition[1], self::HOST_TABLES, true)) {
                preg_match_all('/\bREFERENCES\s+`([^`]+)`/i', $definition[0], $references);
                foreach ($references[1] as $referencedTable) {
                    if ($tables !== null && !isset($tables[$referencedTable])) {
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
                && ($tables === null || isset($tables[$target[1]]))
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
        return self::modules($serverRoot);
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
