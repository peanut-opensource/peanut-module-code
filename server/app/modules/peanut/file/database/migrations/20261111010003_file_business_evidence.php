<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Durable command identity and immutable fact headers; bytes/references stay in File's existing ledgers. */
final class FileBusinessEvidence extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE `pa_file_evidence_command` (
  `tenant_id` bigint unsigned NOT NULL,
  `module_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `client_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`tenant_id`,`module_key`,`command_key`),
  UNIQUE KEY `uk_file_evidence_command_file` (`tenant_id`,`file_key`),
  CONSTRAINT `fk_file_evidence_command_object` FOREIGN KEY (`tenant_id`,`file_key`) REFERENCES `pa_file_object` (`tenant_id`,`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        $this->execute(<<<'SQL'
CREATE TABLE `pa_file_evidence_fact` (
  `tenant_id` bigint unsigned NOT NULL,
  `module_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_type` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `basis_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `files_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`tenant_id`,`module_key`,`owner_type`,`owner_key`),
  CONSTRAINT `fk_file_evidence_fact_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Evidence command identity and financial retention cannot be discarded.');
    }
}
