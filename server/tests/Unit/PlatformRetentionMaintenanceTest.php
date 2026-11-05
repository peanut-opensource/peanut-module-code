<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\InstanceExecutionContext;
use app\common\services\audit\AuditContractHost;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore;
use PeanutAdmin\Modules\Integration\Infrastructure\Persistence\ThinkPhpIntegrationSecurityRepository;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** 实际ORM/事务/审计在独立SQLite；information_schema仅为该夹具的显式元数据适配，不证明MySQL资格。 */
final class PlatformRetentionMaintenanceTest extends TestCase
{
    private PDO $pdo;
    private ExecutionContextStore $contexts;
    private CurrentExecutionContext $current;
    private AuditContractHost $audit;
    private ImportExportStore $imports;
    private ThinkPhpIntegrationSecurityRepository $integration;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/tests/Support/ThinkPhpTestConnection.php';
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($root . '/.local/tmp/retention-maintenance-tests');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2031-01-02 00:00:00.000', 1);
        $this->pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'retention_fixture', 0);
        $this->pdo->exec(<<<'SQL'
            PRAGMA foreign_keys=ON;
            CREATE TABLE pa_import_export_operation (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, retention_until TEXT, result_file_key TEXT, error_file_key TEXT, input_file_key TEXT, revision INTEGER, updated_at TEXT);
            CREATE TABLE pa_import_export_row_error (id INTEGER PRIMARY KEY, tenant_id INTEGER);
            INSERT INTO pa_import_export_operation VALUES
                (1,101,'succeeded','2031-01-01 00:00:00.000','result-1','error-1','input-1',1,'2031-01-01 00:00:00.000'),
                (2,202,'failed','2031-01-01 00:00:00.000','result-2','error-2','input-2',1,'2031-01-01 00:00:00.000'),
                (3,101,'cancelled','2031-01-02 00:00:00.000','result-3','error-3','input-3',1,'2031-01-01 00:00:00.000'),
                (4,101,'running','2031-01-01 00:00:00.000','result-4',NULL,'input-4',1,'2031-01-01 00:00:00.000'),
                (5,202,'succeeded','2032-01-01 00:00:00.000','result-5',NULL,'input-5',1,'2031-01-01 00:00:00.000');
            ATTACH DATABASE ':memory:' AS information_schema;
            CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT);
            INSERT INTO information_schema.COLUMNS VALUES ('retention_fixture','pa_import_export_operation','id'),('retention_fixture','pa_import_export_operation','tenant_id'),('retention_fixture','pa_import_export_row_error','id'),('retention_fixture','pa_import_export_row_error','tenant_id');
            CREATE TABLE pa_platform_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT,event_type TEXT,action TEXT,outcome TEXT,reason_code TEXT,operator_id INTEGER,account_id INTEGER,target_type TEXT,target_id TEXT,request_id TEXT,operation_id TEXT,ip_address TEXT,user_agent_hash TEXT,before_json TEXT,after_json TEXT,metadata_json TEXT,occurred_at TEXT);
            CREATE TABLE pa_integration_webhook_delivery (id INTEGER PRIMARY KEY,tenant_id INTEGER,status TEXT,payload_json TEXT,payload_expires_at TEXT,updated_at TEXT,UNIQUE(tenant_id,id));
            CREATE TABLE pa_integration_webhook_attempt (id INTEGER PRIMARY KEY,tenant_id INTEGER,delivery_id INTEGER,FOREIGN KEY(tenant_id,delivery_id) REFERENCES pa_integration_webhook_delivery(tenant_id,id));
            INSERT INTO pa_integration_webhook_delivery VALUES
                (11,101,'delivered','never-log-this-body','2031-01-01 00:00:00.000','2031-01-01 00:00:00.000'),
                (12,202,'permanent_failed','private-body-two','2031-01-01 00:00:00.000','2031-01-01 00:00:00.000'),
                (13,101,'pending','pending-body','2031-01-01 00:00:00.000','2031-01-01 00:00:00.000'),
                (14,202,'delivered','future-body','2032-01-01 00:00:00.000','2032-01-01 00:00:00.000');
            INSERT INTO pa_integration_webhook_attempt VALUES (1,101,11),(2,101,11),(3,101,11),(4,101,11),(5,101,11),(6,202,12),(7,202,12),(8,101,13);
            SQL);
        \ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->contexts = new ExecutionContextStore();
        $this->current = new CurrentExecutionContext($this->contexts);
        $this->audit = new AuditContractHost($this->current);
        $this->imports = new ImportExportStore($this->current, $this->audit);
        $this->integration = new ThinkPhpIntegrationSecurityRepository($this->current, $this->audit);
    }

    private function runAsMaintenance(string $operation, callable $callback): mixed
    {
        return $this->contexts->run(new InstanceExecutionContext('console.' . $operation, 'retention-test-' . $operation), $callback);
    }

    private function purge(int $limit = 2): array
    {
        $cutoff = new DateTimeImmutable('2031-01-02T00:00:00.000Z');
        return $this->integration->purgeExpiredDeliveryData($cutoff, $cutoff, $limit);
    }

    private function state(): array
    {
        return [
            $this->pdo->query('SELECT * FROM pa_import_export_operation ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query('SELECT * FROM pa_integration_webhook_delivery ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query('SELECT * FROM pa_integration_webhook_attempt ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function denied(callable $callback): void
    {
        $before = $this->state();
        try {
            $callback();
            self::fail('Untrusted maintenance call was accepted.');
        } catch (\Throwable $failure) {
            if ($failure instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $failure;
            }
            self::assertStringContainsString('MAINTENANCE_CONTEXT_REQUIRED', $failure->getMessage());
        }
        self::assertSame($before, $this->state());
    }

    public function testAbsentContextAndUnrelatedInstanceCommandCannotExpireOrPurge(): void
    {
        $this->denied(fn() => $this->imports->expireDue());
        $this->denied(fn() => $this->purge());
        $this->runAsMaintenance('ordinary:task', function (): void {
            $this->denied(fn() => $this->imports->expireDue());
            $this->denied(fn() => $this->purge());
        });
    }

    public function testTenantExecutionCannotBorrowPlatformMaintenanceEvenWithMatchingOperationText(): void
    {
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'retention-tenant', 101, 501, 601, 'admin-web', new DateTimeImmutable('2035-01-01'), 1, 1), 'tenant-request');
        foreach (['import-export:expire', 'integration:purge-expired'] as $operation) {
            $this->contexts->run(new AdminExecutionContext($tenant, 'console.' . $operation), function (): void {
                $this->denied(fn() => $this->imports->expireDue());
                $this->denied(fn() => $this->purge());
            });
        }
    }

    public function testImportExpiryPreservesTerminalConditionsBatchLimitReferencesAndAudit(): void
    {
        $this->runAsMaintenance('import-export:expire', function (): void {
            self::assertSame(1, $this->imports->expireDue(1));
            self::assertSame(2, $this->imports->expireDue(2));
            self::assertSame(0, $this->imports->expireDue(2));
        });
        $rows = $this->state()[0];
        self::assertSame(['expired', 'expired', 'expired', 'running', 'succeeded'], array_column($rows, 'status'));
        self::assertSame([null, null, null, 'result-4', 'result-5'], array_column($rows, 'result_file_key'));
        self::assertSame(['input-1', 'input-2', 'input-3', 'input-4', 'input-5'], array_column($rows, 'input_file_key'));
        self::assertSame([2, 2, 2, 1, 1], array_column($rows, 'revision'));
        self::assertGreaterThanOrEqual(2, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testPurgeBoundsChildrenAsWellAsParentsAndSafelyContinuesAcrossCalls(): void
    {
        $totals = ['payloads_cleared' => 0, 'attempts_deleted' => 0, 'deliveries_deleted' => 0];
        for ($batch = 0; $batch < 8; ++$batch) {
            $result = $this->runAsMaintenance('integration:purge-expired', fn() => $this->purge(2));
            foreach ($result as $key => $count) {
                self::assertLessThanOrEqual(2, $count, $key . ' exceeded the batch bound.');
                $totals[$key] += $count;
            }
            self::assertFalse($this->pdo->inTransaction());
            if (array_sum($result) === 0) {
                break;
            }
        }
        self::assertSame(['payloads_cleared' => 2, 'attempts_deleted' => 7, 'deliveries_deleted' => 2], $totals);
        self::assertSame([13, 14], array_column($this->state()[1], 'id'));
        self::assertSame([8], array_column($this->state()[2], 'id'));
        $audit = json_encode($this->pdo->query('SELECT * FROM pa_platform_audit_event')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('never-log-this-body', $audit);
        self::assertStringNotContainsString('private-body-two', $audit);
        self::assertStringContainsString('payloads_cleared', $audit);
    }

    public function testPurgeCannotHideItsBatchesInsideAnOuterTransaction(): void
    {
        $before = $this->state();
        try {
            $this->runAsMaintenance('integration:purge-expired', fn() => Db::transaction(fn() => $this->purge()));
            self::fail('Nested purge transaction was accepted.');
        } catch (\Throwable $failure) {
            if ($failure instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $failure;
            }
            self::assertStringContainsString('MAINTENANCE_OUTER_TRANSACTION_FORBIDDEN', $failure->getMessage());
        }
        self::assertSame($before, $this->state());
    }

    public function testAuditFailureRollsBackEachOperationBeforeSafeRetry(): void
    {
        $this->pdo->exec("CREATE TRIGGER reject_success_audit BEFORE INSERT ON pa_platform_audit_event WHEN NEW.outcome='success' BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");
        foreach (['import-export:expire' => fn() => $this->imports->expireDue(1), 'integration:purge-expired' => fn() => $this->purge(2)] as $operation => $callback) {
            $before = $this->state();
            try {
                $this->runAsMaintenance($operation, $callback);
                self::fail('Mutation committed despite rejected success audit.');
            } catch (\Throwable $failure) {
                if ($failure instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $failure;
                }
            }
            self::assertSame($before, $this->state());
        }
        $this->pdo->exec('DROP TRIGGER reject_success_audit');
        self::assertSame(1, $this->runAsMaintenance('import-export:expire', fn() => $this->imports->expireDue(1)));
        self::assertSame(2, $this->runAsMaintenance('integration:purge-expired', fn() => $this->purge(2))['attempts_deleted']);
    }

    public function testNativeTopLevelCommandsRunOneBatchAndRestoreContext(): void
    {
        $expire = new \PeanutAdmin\Modules\ImportExport\Console\ExpireOperationsCommand($this->imports, $this->contexts, $this->current);
        self::assertSame(0, $expire->run(new \think\console\Input(['--limit=1']), new \think\console\Output('buffer')));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM pa_import_export_operation WHERE status='expired'")->fetchColumn());
        self::assertTrue($this->contexts->isEmpty());
        $purge = new \PeanutAdmin\Modules\Integration\Console\PurgeExpiredDeliveriesCommand($this->integration, $this->contexts, $this->current);
        self::assertSame(0, $purge->run(new \think\console\Input(['--limit=2', '--payload-cutoff=2031-01-02T00:00:00.000Z', '--evidence-cutoff=2031-01-02T00:00:00Z']), new \think\console\Output('buffer')));
        self::assertSame(6, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_integration_webhook_attempt')->fetchColumn());
        self::assertTrue($this->contexts->isEmpty());
        $config = require dirname(__DIR__, 2) . '/config/console.php';
        self::assertArrayNotHasKey('import-export:expire', $config['module_commands']);
        self::assertArrayNotHasKey('integration:purge-expired', $config['module_commands']);
    }

    public function testNativeCommandCannotElevateAnExistingContextAndRejectsInvalidOptions(): void
    {
        $expire = new \PeanutAdmin\Modules\ImportExport\Console\ExpireOperationsCommand($this->imports, $this->contexts, $this->current);
        $this->runAsMaintenance('import-export:expire', fn() => $this->denied(fn() => $expire->run(new \think\console\Input([]), new \think\console\Output('buffer'))));
        foreach (['0', '1001', '1.5', '-1'] as $limit) {
            $before = $this->state();
            try {
                $expire->run(new \think\console\Input(['--limit=' . $limit]), new \think\console\Output('buffer'));
                self::fail('Invalid batch size admitted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('IMPORT_EXPORT_RETENTION_BATCH_INVALID', $failure->getMessage());
            }
            self::assertSame($before, $this->state());
            self::assertTrue($this->contexts->isEmpty());
        }
        $purge = new \PeanutAdmin\Modules\Integration\Console\PurgeExpiredDeliveriesCommand($this->integration, $this->contexts, $this->current);
        foreach (['tomorrow', '2031-02-30T00:00:00Z', '2031-01-02T00:00:00+00:00'] as $cutoff) {
            try {
                $purge->run(new \think\console\Input(['--payload-cutoff=' . $cutoff, '--evidence-cutoff=2031-01-02T00:00:00Z']), new \think\console\Output('buffer'));
                self::fail('Invalid retention timestamp admitted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('INTEGRATION_RETENTION_CUTOFF_INVALID', $failure->getMessage());
            }
            self::assertTrue($this->contexts->isEmpty());
        }
    }

    public function testPayloadAndEvidenceCutoffsRemainIndependent(): void
    {
        $counts = $this->runAsMaintenance('integration:purge-expired', fn() => $this->integration->purgeExpiredDeliveryData(
            new DateTimeImmutable('2031-01-02T00:00:00Z'),
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
            2,
        ));
        self::assertSame(['payloads_cleared' => 2, 'attempts_deleted' => 0, 'deliveries_deleted' => 0], $counts);
        self::assertCount(4, $this->state()[1]);
        self::assertCount(8, $this->state()[2]);
    }

    public function testNativeModuleProvidersResolveCommandsWithTheSameExecutionAndAudit(): void
    {
        $app = \think\Container::getInstance();
        $app->instance(ExecutionContextStore::class, $this->contexts);
        $app->instance(CurrentExecutionContext::class, $this->current);
        $app->instance(AuditContractHost::class, $this->audit);
        foreach ([new \PeanutAdmin\Modules\ImportExport\ModuleProvider($app), new \PeanutAdmin\Modules\Integration\ModuleProvider()] as $provider) {
            foreach ($provider->bindings() as $abstract => $concrete) {
                $app->bind($abstract, $concrete);
            }
        }
        $expire = $app->make(\PeanutAdmin\Modules\ImportExport\Console\ExpireOperationsCommand::class);
        self::assertSame(0, $expire->run(new \think\console\Input(['--limit=1']), new \think\console\Output('buffer')));
        $purge = $app->make(\PeanutAdmin\Modules\Integration\Console\PurgeExpiredDeliveriesCommand::class);
        self::assertSame(0, $purge->run(new \think\console\Input(['--limit=1', '--payload-cutoff=2031-01-02T00:00:00Z', '--evidence-cutoff=2031-01-02T00:00:00Z']), new \think\console\Output('buffer')));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testStorageModeMismatchRemainsAFailure(): void
    {
        $this->pdo->exec("DELETE FROM information_schema.COLUMNS WHERE TABLE_NAME='pa_import_export_row_error' AND COLUMN_NAME='tenant_id'");
        $store = new ImportExportStore($this->current, $this->audit);
        $before = $this->state();
        try {
            $this->runAsMaintenance('import-export:expire', fn() => $store->expireDue(1));
            self::fail('Mismatched storage mode accepted.');
        } catch (\Throwable $failure) {
            if ($failure instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $failure;
            }
            self::assertStringContainsString('TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH', $failure->getMessage());
        }
        self::assertSame($before, $this->state());
    }
}
