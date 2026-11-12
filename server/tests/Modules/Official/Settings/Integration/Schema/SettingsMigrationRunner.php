<?php

declare(strict_types=1);

namespace Tests\Modules\Official\Settings\Integration\Schema;

use PDO;
use PeanutAdmin\Modules\Settings\Database\Schema;

final readonly class SettingsMigrationRunner
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function migrate(): void
    {
        \IdentityMysqlFixture::install($this->pdo, [
            dirname(__DIR__, 6) . '/app/modules/peanut/settings/database/migrations',
        ]);
    }

    public function rollbackAll(): void
    {
        foreach (array_reverse(Schema::tableNames()) as $table) {
            $this->pdo->exec(Schema::dropSql($table));
        }
    }
}
