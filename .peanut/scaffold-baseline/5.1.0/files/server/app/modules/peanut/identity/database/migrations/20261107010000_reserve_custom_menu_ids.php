<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Advance allocation only. Existing menu IDs, keys and foreign-key references are unchanged. */
final class ReserveCustomMenuIds extends Migrator
{
    public function up(): void
    {
        $row = $this->fetchRow("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_system_menu'");
        if (!is_array($row) || !isset($row['AUTO_INCREMENT'])) {
            throw new RuntimeException('MENU_ID_ALLOCATION_UNAVAILABLE');
        }
        if ((int) $row['AUTO_INCREMENT'] < 1_000_000_000) {
            // MySQL DDL commits independently; keep it out of the role-grant DML migration.
            $this->execute('ALTER TABLE pa_system_menu AUTO_INCREMENT = 1000000000');
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Custom menu allocation cannot be lowered or existing IDs renumbered.');
    }
}
