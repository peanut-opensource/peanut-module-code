<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class CreateDeviceCredentials extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE `pa_device_credential` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `credential_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `client_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `device_fingerprint_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `credential_public_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key_fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `credential_signature` CHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'enrolled',
  `bound_installation_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `bound_at` DATETIME(3) NULL,
  `bound_by_member_id` BIGINT UNSIGNED NULL,
  `revocation_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `revoked_at` DATETIME(3) NULL,
  `revoked_by_member_id` BIGINT UNSIGNED NULL,
  `revoke_reason` VARCHAR(1000) NULL,
  `enroll_source_command_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `enroll_input_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `enroll_source_result_json` JSON NOT NULL,
  `enroll_basis_json` JSON NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_credential_key` (`tenant_id`, `credential_key`),
  UNIQUE KEY `uq_device_credential_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uq_device_credential_public_key` (`tenant_id`, `key_fingerprint`),
  UNIQUE KEY `uq_device_credential_enroll_command` (`tenant_id`, `enroll_source_command_key`),
  KEY `ix_device_credential_installation` (`tenant_id`, `bound_installation_key`),
  KEY `ix_device_credential_status` (`tenant_id`, `status`, `credential_key`),
  CONSTRAINT `fk_device_credential_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_device_credential_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_device_credential_updater` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_device_credential_binder` FOREIGN KEY (`tenant_id`, `bound_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_device_credential_revoker` FOREIGN KEY (`tenant_id`, `revoked_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `ck_device_credential_revision` CHECK (`revision` >= 1),
  CONSTRAINT `ck_device_credential_status` CHECK (`status` IN ('enrolled', 'bound', 'revoked')),
  CONSTRAINT `ck_device_credential_binding_group` CHECK (
    (`bound_installation_key` IS NULL AND `bound_at` IS NULL AND `bound_by_member_id` IS NULL AND `status` IN ('enrolled', 'revoked'))
    OR (`bound_installation_key` IS NOT NULL AND `bound_at` IS NOT NULL AND `bound_by_member_id` IS NOT NULL AND `status` IN ('bound', 'revoked'))
  ),
  CONSTRAINT `ck_device_credential_revocation_group` CHECK (
    (`status` IN ('enrolled', 'bound') AND `revocation_epoch` = 0 AND `revoked_at` IS NULL AND `revoked_by_member_id` IS NULL AND `revoke_reason` IS NULL)
    OR (`status` = 'revoked' AND `revocation_epoch` >= 1 AND `revoked_at` IS NOT NULL AND `revoked_by_member_id` IS NOT NULL AND `revoke_reason` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Device credential enrollment and installation history must remain traceable.');
    }
}
