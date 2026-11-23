<?php

declare(strict_types=1);

use think\migration\Migrator;

/** Migrate local storage_space paths from public/storage & private/storage to storage/public & storage/private. */
final class MigrateStorageSpaceLocalPaths extends Migrator
{
    public function up(): void
    {
        if (!$this->hasTable('storage_space')) {
            return;
        }

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
        if (!$this->hasTable('storage_space')) {
            return;
        }

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

        $this->execute(sprintf("UPDATE `%s` SET `local_path` = 'public/storage' WHERE `local_path` = 'storage/public'", $table));
        $this->execute(sprintf("UPDATE `%s` SET `local_path` = 'private/storage' WHERE `local_path` = 'storage/private'", $table));
        $this->execute(sprintf(
            "ALTER TABLE `%s` ADD CONSTRAINT `chk_storage_space_local_path` CHECK (((`local_path` is null) or (`local_path` in (_utf8mb4'public/storage',_utf8mb4'private/storage'))))",
            $table
        ));
    }
}
