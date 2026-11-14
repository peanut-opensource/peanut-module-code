<?php

declare(strict_types=1);

use think\migration\Migrator;

final class MenuCustomPermission extends Migrator
{
    public function up(): void
    {
        $this->execute('ALTER TABLE pa_menu_definition ADD COLUMN instance_permission_id bigint unsigned DEFAULT NULL, ADD CONSTRAINT fk_menu_instance_permission FOREIGN KEY (instance_permission_id) REFERENCES pa_permission (id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE pa_menu_definition DROP FOREIGN KEY fk_menu_instance_permission, DROP COLUMN instance_permission_id');
    }
}
