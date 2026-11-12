<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class PreservePaymentMerchantBindings extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE pa_external_channel_binding
    DROP INDEX uk_external_tenant_provider,
    ADD COLUMN retired_at int unsigned NULL,
    ADD COLUMN current_key tinyint unsigned GENERATED ALWAYS AS (IF(retired_at IS NULL, 1, NULL)) STORED,
    ADD UNIQUE KEY uk_external_tenant_provider_current (tenant_id, provider, current_key)
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Historical merchant bindings and callback keys are retained.');
    }
}
