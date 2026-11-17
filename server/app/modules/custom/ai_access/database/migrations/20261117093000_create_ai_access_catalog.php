<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class CreateAiAccessCatalog extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE pa_ai_access_catalog (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(16) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            entry_key VARCHAR(64) NOT NULL,
            name VARCHAR(120) NOT NULL,
            enabled TINYINT UNSIGNED NOT NULL,
            priority INT UNSIGNED NOT NULL DEFAULT 0,
            details JSON NOT NULL,
            secret_payload JSON DEFAULT NULL,
            selection_order BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY catalog_identity (tenant_id, kind, provider, entry_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->execute("CREATE TABLE pa_ai_access_request (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id BIGINT UNSIGNED NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL,
            request_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            provider_key VARCHAR(64) NOT NULL,
            model_key VARCHAR(64) NOT NULL,
            credential_key VARCHAR(64) NOT NULL,
            capability VARCHAR(16) NOT NULL,
            status VARCHAR(24) NOT NULL,
            provider_request_id VARCHAR(180) DEFAULT NULL,
            usage JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY member_request (tenant_id, member_id, request_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Retain configured AI credentials and request history.');
    }
}
