<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class AdoptWorkbenchPermissionOwner extends Migrator
{
    public function up(): void
    {
        // Preserve the existing permission ID and role grants when adopting the core catalog.
        $this->execute("UPDATE pa_permission SET module_key = 'core' WHERE BINARY `key` = 'workbench/index' AND BINARY module_key = 'peanut.admin'");
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('The workbench permission remains owned by the core catalog.');
    }
}
