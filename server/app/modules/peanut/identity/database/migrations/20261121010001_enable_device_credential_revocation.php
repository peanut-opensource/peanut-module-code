<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class EnableDeviceCredentialRevocation extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE `pa_device_credential`
  ADD COLUMN `revoke_source_command_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `revoke_reason`,
  ADD COLUMN `revoke_input_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `revoke_source_command_key`,
  ADD COLUMN `revoke_source_result_json` JSON NULL AFTER `revoke_input_hash`,
  ADD COLUMN `revoke_basis_json` JSON NULL AFTER `revoke_source_result_json`,
  ADD UNIQUE KEY `uq_device_credential_revoke_command` (`tenant_id`, `revoke_source_command_key`),
  ADD CONSTRAINT `ck_device_credential_revoke_source_group` CHECK (
    (`revoke_source_command_key` IS NULL AND `revoke_input_hash` IS NULL AND `revoke_source_result_json` IS NULL AND `revoke_basis_json` IS NULL)
    OR (`revoke_source_command_key` IS NOT NULL AND `revoke_input_hash` IS NOT NULL AND `revoke_source_result_json` IS NOT NULL AND `revoke_basis_json` IS NOT NULL AND `status` = 'revoked')
  );
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Device credential revocation history requires an explicitly reviewed recovery.');
    }
}
