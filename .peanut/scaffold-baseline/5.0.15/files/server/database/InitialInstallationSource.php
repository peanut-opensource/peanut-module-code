<?php

declare(strict_types=1);

/** First-install inputs; execution and migration history remain owned by ThinkPHP. */
final class InitialInstallationSource
{
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
                $selected[] = $definition[0];
            }
        }
        if ($selected === []) {
            throw new RuntimeException('INSTALL_INITIAL_SCHEMA_MISSING');
        }

        $data = self::read($serverRoot . '/database/schema/baseline-data.sql');
        // Frozen data also contains historical business/menu conversions. Fresh installs
        // use defaults only; current catalogs are synchronized from their actual owners.
        $data = explode('-- peanut-baseline-catalog-boundary', $data, 2)[0];
        preg_match_all('/^INSERT\b.*?;(?=\r?\n|$)/ms', $data, $statements);
        $defaults = [];
        foreach ($statements[0] as $statement) {
            if (preg_match('/^INSERT(?: IGNORE)? INTO `([^`]+)`/', $statement, $target) === 1
                && isset($tables[$target[1]])
                && in_array($target[1], ['pa_system_dict_type', 'pa_system_dict_data', 'pa_tenant_setting', 'pa_storage_account', 'pa_storage_space', 'pa_storage_route', 'pa_crontab'], true)) {
                if ($target[1] === 'pa_crontab'
                    && (!in_array('peanut.content-tools', $modules, true) || !str_contains($statement, "'generator:cleanup'"))) {
                    continue;
                }
                $defaults[] = $statement;
            }
        }
        return [
            'schema/baseline-schema.sql' => implode("\n\n", $selected) . "\n",
            'schema/baseline-data.sql' => implode("\n\n", $defaults) . "\n",
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
