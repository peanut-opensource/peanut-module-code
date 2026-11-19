<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class InitialScaffoldBaseline extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialScaffoldBaseline requires the native PDO adapter.');
        }
        $sql = file_get_contents(__DIR__ . '/../schema/baseline-schema.sql');
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('The initial schema source is missing.');
        }
        preg_match_all('/^CREATE TABLE `([^`]+)` \(\n.*?^\) ENGINE=[^\r\n]+;$/ms', $sql, $definitions, PREG_SET_ORDER);
        if ($definitions === []) {
            throw new RuntimeException('The initial schema definitions are missing.');
        }
        foreach ($definitions as $definition) {
            if (!$adapter->hasTable($definition[1])) {
                $this->execute($definition[0]);
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('The initial application schema cannot be rolled back. Reinstallation requires an explicitly selected empty database.');
    }
}
