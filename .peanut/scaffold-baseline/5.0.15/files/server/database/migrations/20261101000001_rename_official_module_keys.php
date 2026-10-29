<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class RenameOfficialModuleKeys extends Migrator
{
    private const SLUGS = [
        'article', 'collaboration', 'content-tools', 'document', 'file', 'identity',
        'import-export', 'integration', 'member', 'notification', 'oauth', 'ops',
        'payment', 'reference-codes', 'rich-text', 'settings', 'task',
    ];

    // Explicit consumers from the Host baseline and their production catalogs.
    // No business payload, installation source identity, digest, value or audit snapshot is rewritten.
    private const MODULE_COLUMNS = [
        'pa_module_installation' => 'module_key',
        'pa_tenant_module' => 'module_key',
        'pa_plugin_module' => 'module_key',
        'pa_permission' => 'module_key',
        'pa_protected_resource' => 'module_key',
        'pa_target_type' => 'module_key',
        'pa_data_condition_definition' => 'module_key',
        'pa_menu_definition' => 'module_key',
        'pa_system_menu' => 'module_key',
        'pa_setting_definition' => 'module_key',
        'pa_reference_code_set' => 'module_key',
        'pa_document' => 'owner_module_key',
        'pa_file_reference' => 'owner_module_key',
        'pa_workflow_definition' => 'module_key',
        'pa_workflow_instance' => 'module_key',
    ];

    private const CODE_COLUMNS = [
        'pa_permission' => ['key'],
        'pa_protected_resource' => ['key', 'provider_key'],
        'pa_target_type' => ['key', 'resolver_key', 'catalog_provider_key'],
        'pa_data_condition_definition' => ['key'],
        'pa_resource_operation_condition' => ['selector_resource_key'],
        'pa_menu_definition' => ['key', 'parent_key', 'route_name', 'component_key'],
        'pa_system_menu' => ['menu_key', 'parent_key', 'perms', 'component'],
        'pa_setting_definition' => ['target_resource_key'],
        'pa_setting_target_value' => ['target_resource_key'],
        'pa_data_permission_target_set' => ['target_resource_key'],
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter || $adapter->getAdapterType() !== 'mysql') {
            throw new RuntimeException('RenameOfficialModuleKeys requires the native MySQL PDO adapter.');
        }
        // A fresh Host installs selected Module schemas and catalogs afterwards.
        if (!$adapter->hasTable('pa_module_installation')) {
            return;
        }
        $pdo = $adapter->getConnection();
        // think-migration owns the transaction and successful migration ledger entry.
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('RenameOfficialModuleKeys requires the native migration transaction.');
        }
        $location = 'preflight';
        try {
            // Native retry returns jobs to queued. Only these two production paths
            // changed signed execution identities; terminal history remains untouched.
            $location = 'pa_task_job.pending_execution_identity';
            $query = $pdo->query(<<<'SQL'
SELECT `id` FROM `pa_task_job`
WHERE `status` IN ('queued', 'running') AND (
    `task_type` = 'official.task.crontab' OR `handler_key` = 'official.task.crontab.execute'
    OR (`handler_key` IN ('notification.inbox', 'notification.sms')
        AND CASE WHEN JSON_VALID(`trusted_envelope`)
            THEN JSON_UNQUOTE(JSON_EXTRACT(`trusted_envelope`, '$.payload.resource_key'))
            ELSE NULL END = 'official.notification.delivery')
)
ORDER BY `id` FOR UPDATE
SQL);
            $pendingCount = 0;
            $pendingIds = [];
            while (($id = $query->fetchColumn()) !== false) {
                ++$pendingCount;
                if (count($pendingIds) < 20) {
                    $pendingIds[] = (string) $id;
                }
            }
            $query->closeCursor();
            if ($pendingCount > 0) {
                throw new RuntimeException('MODULE_KEY_RENAME_PENDING_TASKS: pa_task_job count=' . $pendingCount
                    . ' ids(first 20)=' . implode(',', $pendingIds)
                    . '; original task owners must drain or cancel these jobs through the existing lifecycle before renaming; signed envelopes cannot be rewritten.');
            }
            foreach (self::SLUGS as $slug) {
                $old = 'official.' . $slug;
                $new = 'peanut.' . $slug;
                // Only these identities are globally unique. Scoped owners may coexist;
                // their actual composite unique constraints decide collisions on update.
                foreach ([
                    'pa_plugin_installation' => 'plugin_key',
                    'pa_module_installation' => 'module_key',
                    'pa_plugin_module' => 'module_key',
                ] as $table => $column) {
                    $location = $table . '.' . $column;
                    $query = $pdo->prepare("SELECT DISTINCT `$column` FROM `$table` WHERE `$column` IN (?, ?) FOR UPDATE");
                    $query->execute([$old, $new]);
                    $keys = $query->fetchAll(PDO::FETCH_COLUMN);
                    $query->closeCursor();
                    if (in_array($old, $keys, true) && in_array($new, $keys, true)) {
                        throw new RuntimeException('MODULE_KEY_RENAME_CONFLICT: ' . $location . ' ' . $old . ' -> ' . $new);
                    }
                }
            }
            foreach (self::SLUGS as $slug) {
                $location = 'pa_plugin_installation.plugin_key';
                $this->renamePlugin($pdo, 'official.' . $slug, 'peanut.' . $slug);
            }
            foreach (self::MODULE_COLUMNS as $table => $column) {
                if (!$adapter->hasTable($table)) {
                    continue;
                }
                foreach (self::SLUGS as $slug) {
                    $location = $table . '.' . $column;
                    $query = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE BINARY `$column` = BINARY ?");
                    $query->execute(['peanut.' . $slug, 'official.' . $slug]);
                }
            }
            foreach (self::CODE_COLUMNS as $table => $columns) {
                if (!$adapter->hasTable($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    $location = $table . '.' . $column;
                    foreach (self::SLUGS as $slug) {
                        $old = 'official.' . $slug;
                        $new = 'peanut.' . $slug;
                        $query = $pdo->prepare("UPDATE `$table` SET `$column` = CONCAT(?, SUBSTRING(`$column`, ?)) WHERE BINARY `$column` = BINARY ? OR BINARY LEFT(`$column`, ?) = BINARY ?");
                        $query->execute([$new, strlen($old) + 1, $old, strlen($old) + 1, $old . '.']);
                    }
                }
            }
            // These objects are the menu repository's explicit comparison baseline.
            // Renaming only declared key fields preserves customized presentation and conflict records.
            foreach ([
                'pa_menu_definition' => ['module_key', 'parent_key', 'route_name', 'component_key'],
                'pa_system_menu' => ['module_key', 'menu_key', 'parent_key', 'perms', 'component'],
            ] as $table => $fields) {
                $location = $table . '.upstream_defaults_json';
                $query = $pdo->query("SELECT `id`, `upstream_defaults_json` FROM `$table` WHERE JSON_TYPE(`upstream_defaults_json`) = 'OBJECT' AND JSON_SEARCH(`upstream_defaults_json`, 'one', 'official.%') IS NOT NULL FOR UPDATE");
                $rows = $query->fetchAll(PDO::FETCH_ASSOC);
                $query->closeCursor();
                foreach ($rows as $row) {
                    $defaults = json_decode($row['upstream_defaults_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($defaults) || array_is_list($defaults)) {
                        throw new RuntimeException('MODULE_KEY_RENAME_INVALID_MENU_DEFAULTS: ' . $table . ' id=' . $row['id']);
                    }
                    $changed = false;
                    foreach ($fields as $field) {
                        if (!is_string($defaults[$field] ?? null)) {
                            continue;
                        }
                        foreach (self::SLUGS as $slug) {
                            $old = 'official.' . $slug;
                            if ($defaults[$field] === $old || str_starts_with($defaults[$field], $old . '.')) {
                                $defaults[$field] = 'peanut.' . $slug . substr($defaults[$field], strlen($old));
                                $changed = true;
                                break;
                            }
                        }
                    }
                    if ($changed) {
                        $update = $pdo->prepare("UPDATE `$table` SET `upstream_defaults_json` = ? WHERE `id` = ?");
                        $update->execute([json_encode($defaults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $row['id']]);
                    }
                }
            }
        } catch (Throwable $exception) {
            // The locked package does not roll back exceptions in executeMigration().
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('MODULE_KEY_RENAME_FAILED at ' . $location . ': ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function renamePlugin(PDO $pdo, string $old, string $new): void
    {
        $query = $pdo->prepare('SELECT * FROM `pa_plugin_installation` WHERE `plugin_key` = ? FOR UPDATE');
        $query->execute([$old]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $query->closeCursor();
        if ($row === false) {
            return;
        }
        // The parent has a numeric ID, while the sole baseline FK uses its string key
        // with ON UPDATE RESTRICT. A transaction-local parent lets that original ID survive.
        $temporary = 'module-key-rename.' . substr($old, strlen('official.'));
        $query = $pdo->prepare('SELECT `id` FROM `pa_plugin_installation` WHERE `plugin_key` IN (?, ?) FOR UPDATE');
        $query->execute([$new, $temporary]);
        if ($query->fetchColumn() !== false) {
            throw new RuntimeException('MODULE_KEY_RENAME_CONFLICT: pa_plugin_installation.plugin_key ' . $old . ' -> ' . $new);
        }
        $query->closeCursor();
        unset($row['id']);
        $row['plugin_key'] = $temporary;
        $columns = '`' . implode('`, `', array_keys($row)) . '`';
        $query = $pdo->prepare('INSERT INTO `pa_plugin_installation` (' . $columns . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')');
        $query->execute(array_values($row));
        $temporaryId = $pdo->lastInsertId();
        $query = $pdo->prepare('UPDATE `pa_plugin_module` SET `plugin_key` = ? WHERE `plugin_key` = ?');
        $query->execute([$temporary, $old]);
        $query = $pdo->prepare('UPDATE `pa_plugin_installation` SET `plugin_key` = ? WHERE `plugin_key` = ?');
        $query->execute([$new, $old]);
        $query = $pdo->prepare('UPDATE `pa_plugin_module` SET `plugin_key` = ? WHERE `plugin_key` = ?');
        $query->execute([$new, $temporary]);
        $query = $pdo->prepare('DELETE FROM `pa_plugin_installation` WHERE `id` = ? AND `plugin_key` = ?');
        $query->execute([$temporaryId, $temporary]);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Official module key renaming is forward-only; later package reconciliation must retain its installed facts.');
    }
}
