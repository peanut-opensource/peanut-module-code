<?php

declare(strict_types=1);

use app\command\TenantDataInventory;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use think\App;
use think\console\Input;
use think\console\Output;

final class TenantDataInventoryTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnlyCountsTheRequestedTenantInBaseTables(): void
    {
        $app = new App();
        require_once $app->getThinkPath() . 'helper.php';
        $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File']]], 'cache');
        $app->config->set([
            'default' => 'fixture',
            'connections' => ['fixture' => ['type' => 'sqlite', 'database' => ':memory:', 'trigger_sql' => false]],
        ], 'database');
        $connection = $app->db->connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'main', 0);
        $pdo->exec(<<<'SQL'
            ATTACH DATABASE ':memory:' AS information_schema;
            CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT);
            CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, TABLE_TYPE TEXT);
            INSERT INTO information_schema.COLUMNS VALUES
              ('main', 'pa_order', 'tenant_id'), ('main', 'pa_order', 'id'),
              ('main', 'pa_empty', 'tenant_id'), ('main', 'pa_view', 'tenant_id'),
              ('other', 'other_tenant_data', 'tenant_id'), ('main', 'platform', 'id');
            INSERT INTO information_schema.TABLES VALUES
              ('main', 'pa_order', 'BASE TABLE'), ('main', 'pa_empty', 'BASE TABLE'),
              ('main', 'pa_view', 'VIEW'), ('main', 'platform', 'BASE TABLE'),
              ('other', 'other_tenant_data', 'BASE TABLE');
            CREATE TABLE pa_order (tenant_id INTEGER, id INTEGER);
            CREATE TABLE pa_empty (tenant_id INTEGER);
            INSERT INTO pa_order VALUES (27, 1), (27, 2), (89, 3);
            INSERT INTO pa_empty VALUES (89);
            SQL);
        $context = new ExecutionContextStore();
        $command = new TenantDataInventory($context, new CurrentExecutionContext($context));
        $output = new Output('buffer');
        self::assertSame(0, $command->run(new Input(['27']), $output));
        self::assertSame([
            'tenant_id' => 27,
            'tables' => [['table' => 'pa_empty', 'row_count' => 0], ['table' => 'pa_order', 'row_count' => 2]],
            'total_rows' => 2,
        ], json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM pa_order')->fetchColumn());
        self::assertTrue($context->isEmpty());
    }

    public function testRejectsAnInvalidTenantBeforeOpeningTheDatabase(): void
    {
        $context = new ExecutionContextStore();
        $command = new TenantDataInventory($context, new CurrentExecutionContext($context));
        $this->expectException(InvalidArgumentException::class);
        $command->run(new Input(['0']), new Output('buffer'));
    }
}
