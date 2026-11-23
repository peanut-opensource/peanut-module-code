<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ContextualCommand;
use InvalidArgumentException;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;

final class TenantDataInventory extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('tenant:data-inventory')
            ->setDescription('Count rows owned by one tenant in the current database')
            ->addArgument('tenant_id', Argument::REQUIRED, 'Positive tenant identifier');
    }

    protected function handle(Input $input, Output $output): int
    {
        if (!$this->establishedInstanceContext()) {
            throw new \LogicException('Tenant inventory requires a top-level instance CLI command.');
        }
        $value = (string) $input->getArgument('tenant_id');
        $tenantId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tenantId === false || (string) $tenantId !== $value) {
            throw new InvalidArgumentException('tenant_id must be a positive integer.');
        }

        $database = Db::connect();
        $inventory = self::inventory($tenantId, $database->query(...));
        $output->writeln(json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return 0;
    }

    /**
     * @param callable(string, array): array $query
     * @return array{tenant_id:int,tables:list<array{table:string,row_count:int}>,total_rows:int}
     */
    private static function inventory(int $tenantId, callable $query): array
    {
        $schemaTables = $query(<<<'SQL'
            SELECT c.TABLE_NAME AS table_name
            FROM information_schema.COLUMNS AS c
            JOIN information_schema.TABLES AS t
              ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'tenant_id' AND t.TABLE_TYPE = 'BASE TABLE'
            ORDER BY c.TABLE_NAME
            SQL, []);

        $tables = [];
        $total = 0;
        foreach ($schemaTables as $metadata) {
            $table = $metadata['table_name'];
            // Table names come from the current schema, and backtick escape preserves identifier semantics.
            $identifier = '`' . str_replace('`', '``', $table) . '`';
            $rows = $query("SELECT COUNT(*) AS row_count FROM {$identifier} WHERE `tenant_id` = ?", [$tenantId]);
            $count = (int) $rows[0]['row_count'];
            $tables[] = ['table' => $table, 'row_count' => $count];
            $total += $count;
        }
        return ['tenant_id' => $tenantId, 'tables' => $tables, 'total_rows' => $total];
    }
}
