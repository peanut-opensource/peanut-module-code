<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Revocation and retry receipts must survive process exit; no data is copied from consumer private tables. */
final class DocumentTaskSourceGrants extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE pa_document_source_grant (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint unsigned NOT NULL,
  grant_key varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_id bigint unsigned NOT NULL,
  revision_id bigint unsigned NOT NULL,
  owner_json json NOT NULL,
  owner_revision varchar(256) COLLATE utf8mb4_bin NOT NULL,
  document_auth_revision bigint unsigned NOT NULL,
  snapshot_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version_digest char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  account_id bigint unsigned NOT NULL,
  member_id bigint unsigned NOT NULL,
  session_key varchar(256) COLLATE utf8mb4_bin NOT NULL,
  client_key varchar(128) COLLATE utf8mb4_bin NOT NULL,
  authorization_revision bigint unsigned NOT NULL,
  tenant_authorization_revision bigint unsigned NOT NULL,
  binding_json json NOT NULL,
  expires_at bigint unsigned NOT NULL,
  revoked_at bigint unsigned DEFAULT NULL,
  issued_at bigint unsigned NOT NULL,
  request_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_document_source_grant (tenant_id,grant_key),
  UNIQUE KEY uk_document_source_request (tenant_id,member_id,request_key),
  KEY idx_document_source_version (tenant_id,document_id,revision_id),
  KEY idx_document_source_expiry (tenant_id,expires_at),
  CONSTRAINT fk_document_source_document FOREIGN KEY (tenant_id,document_id) REFERENCES pa_document (tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Removing durable source grants would lose revocation and replay evidence.');
    }
}
