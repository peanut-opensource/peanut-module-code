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
        $pdo = $adapter->getConnection();
        preg_match_all('/^CREATE TABLE `([^`]+)` \(\n.*?^\) ENGINE=[^\r\n]+;$/ms', $sql, $definitions, PREG_SET_ORDER);
        if ($definitions === []) {
            throw new RuntimeException('The initial schema definitions are missing.');
        }
        $pending = [];
        foreach ($definitions as $definition) {
            $table = $definition[1];
            if (!$adapter->hasTable($table)) {
                $pending[] = $definition[0];
                continue;
            }
            $statement = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $existing = $statement->fetch(PDO::FETCH_NUM);
            $statement->closeCursor();
            // Retaining a live table requires its actual complete definition, not a fabricated applied version.
            if (!is_array($existing) || !is_string($existing[1] ?? null)
                || $this->schemaDefinition($existing[1]) !== $this->schemaDefinition($definition[0])) {
                throw new RuntimeException('INITIAL_SCHEMA_MISMATCH: ' . $table);
            }
        }
        // Module migrations run first. Check every existing definition before creating
        // the Host tables in their dependency order with native foreign-key checks.
        foreach ($pending as $definition) {
            $this->execute($definition);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('The initial application schema cannot be rolled back. Reinstallation requires an explicitly selected empty database.');
    }

    private function schemaDefinition(string $definition): string
    {
        // AUTO_INCREMENT is live row state; fresh installations start their own sequence.
        return (string) preg_replace('/ AUTO_INCREMENT=\d+(?= |$)/', '', rtrim(trim($definition), ';'));
    }
}
