<?php

declare(strict_types=1);

namespace Tests\Modules\Official\ReferenceCodes\Versioned\Integration\Schema;

use PDO;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Database\Schema;

final readonly class ReferenceCodesMigrationRunner
{
    public function __construct(private PDO $pdo) {}

    public function migrate(): void
    {
        \IdentityMysqlFixture::install($this->pdo, [
            dirname(__DIR__, 7) . '/app/modules/peanut/reference_codes/database/migrations',
        ]);
    }

    public function rollbackAll(): void
    {
        foreach (array_reverse(Schema::tableNames()) as $table) {
            $this->pdo->exec(Schema::dropSql($table));
        }
    }
}
