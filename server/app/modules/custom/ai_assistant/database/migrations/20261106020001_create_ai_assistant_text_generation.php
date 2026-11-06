<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class CreateAiAssistantTextGeneration extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE pa_ai_assistant_text_generation (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            tenant_id bigint unsigned NOT NULL,
            created_by bigint unsigned NOT NULL,
            request_id varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            request_hash char(64) NOT NULL,
            status varchar(24) NOT NULL,
            details json NOT NULL,
            candidate mediumtext DEFAULT NULL,
            created_at bigint unsigned NOT NULL,
            updated_at bigint unsigned NOT NULL,
            PRIMARY KEY(id), UNIQUE KEY member_request(tenant_id,created_by,request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Retain member candidates and provider request evidence.');
    }
}
