<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Runs after the Ops initial schema through the same native migration ledger. */
final class ModuleInstallRequests extends Migrator
{
    public function up(): void
    {
        $constraint = $this->fetchRow("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_ops_module_request_operation'");
        if (!is_array($constraint)) {
            throw new RuntimeException('OPS_MODULE_REQUEST_SCHEMA_UNAVAILABLE');
        }
        // An interrupted/repeated migration retains existing requests and recognizes the target operation constraint.
        if (str_contains((string) $constraint['CHECK_CLAUSE'], "'install'")) {
            return;
        }
        $this->execute("ALTER TABLE `pa_ops_module_request`
            DROP CHECK `chk_ops_module_request_archive`,
            DROP CHECK `chk_ops_module_request_operation`,
            ADD CONSTRAINT `chk_ops_module_request_archive` CHECK ((`operation` IN ('install','update')) = (`archive_sha256` IS NOT NULL)),
            ADD CONSTRAINT `chk_ops_module_request_operation` CHECK (`operation` IN ('install','update','retire','purge'))");
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Module install request history is retained. Reverting the request contract requires an explicit data migration.');
    }
}
