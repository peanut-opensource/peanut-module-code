<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Migrate local storage_space paths from public/storage & private/storage to storage/public & storage/private. */
final class MigrateStorageSpaceLocalPaths extends Migrator
{
    public function up(): void
    {
        if (!$this->hasTable('storage_space')) {
            return;
        }

        $this->requirePreparedStorageLayout();

        $prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? 'pa_');
        $table = $prefix . 'storage_space';

        $constraint = $this->fetchRow(
            sprintf(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND CONSTRAINT_NAME = 'chk_storage_space_local_path'",
                addslashes($table)
            )
        );
        if ($constraint) {
            $this->execute(sprintf('ALTER TABLE `%s` DROP CHECK `chk_storage_space_local_path`', $table));
        }

        $this->execute(sprintf("UPDATE `%s` SET `local_path` = 'storage/public' WHERE `local_path` = 'public/storage'", $table));
        $this->execute(sprintf("UPDATE `%s` SET `local_path` = 'storage/private' WHERE `local_path` = 'private/storage'", $table));
        $this->execute(sprintf(
            "ALTER TABLE `%s` ADD CONSTRAINT `chk_storage_space_local_path` CHECK (((`local_path` is null) or (`local_path` in (_utf8mb4'storage/public',_utf8mb4'storage/private'))))",
            $table
        ));
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Storage files have moved. Restore the paired backup with its matching application version instead of redirecting database paths.',
        );
    }

    private function requirePreparedStorageLayout(): void
    {
        $root = rtrim(app()->getRootPath(), '/\\');
        $public = $root . '/storage/public';
        $private = $root . '/storage/private';
        $publicEntry = $root . '/public/storage';
        $legacyPrivate = $root . '/private/storage';
        if (!is_dir($public) || is_link($public)
            || !is_dir($private) || is_link($private)
            || !is_link($publicEntry) || realpath($publicEntry) !== realpath($public)
            || file_exists($legacyPrivate) || is_link($legacyPrivate)) {
            throw new RuntimeException(
                'Prepare the host storage layout before migrating File database paths. Run python3 '
                . $root . '/docker/scripts/migrate-storage-layout.py ' . $root . ' and retry the native module migration.',
            );
        }
    }
}
