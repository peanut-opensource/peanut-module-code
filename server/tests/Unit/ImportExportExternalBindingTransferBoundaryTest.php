<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\ExternalBindingConfigurationAdapter;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingTransfer;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Modules\Integration\ModuleProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use think\App;
use think\facade\Db;

/** Native container and ORM with synthetic in-memory state, not MySQL concurrency or HTTP authorization. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ImportExportExternalBindingTransferBoundaryTest extends TestCase
{
    private PDO $database;
    private App $app;
    private ExternalBindingTransfer $transfer;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/external-binding-transfer-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new RuntimeException('BINDING_TRANSFER_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($temporary . '/case-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, code TEXT, status TEXT);
            CREATE TABLE pa_external_channel_binding (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, provider TEXT, callback_key TEXT, identity_hash TEXT, identity_hint TEXT, config_json TEXT, status INTEGER, create_time INTEGER, update_time INTEGER, UNIQUE(tenant_id, provider));
            INSERT INTO pa_tenant VALUES (1, 'alpha', 'active'), (2, 'beta', 'active');
            SQL);
        $this->app->instance(AdminDirectoryQuery::class, new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore())));
        $this->app->bind((new ModuleProvider())->bindings());
        $this->transfer = $this->app->make(ExternalBindingTransfer::class);
    }

    private function actor(int $tenantId = 1): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $tenantId,
            'synthetic-session',
            $tenantId,
            101,
            201,
            'admin-web',
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
            3,
            3,
        ), 'synthetic-binding-transfer');
    }

    private function value(string $appId = 'synthetic-app'): array
    {
        return ['identity_hash' => hash('sha256', $appId), 'identity_hint' => 'synthetic', 'config' => ['app_id' => $appId, 'api_secret' => 'synthetic-test-secret'], 'status' => true];
    }

    private function apply(array $value, ?int $revision = null, int $tenantId = 1): void
    {
        Db::transaction(fn() => $this->transfer->apply($this->actor($tenantId), 'payment.wechat', $value, $revision));
    }

    private function rows(): array
    {
        return $this->database->query('SELECT * FROM pa_external_channel_binding ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testAdapterUsesTheExportedIntegrationCapabilityAndNoPersistence(): void
    {
        $adapter = $this->app->make(ExternalBindingConfigurationAdapter::class);
        $constructor = (new ReflectionClass($adapter))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(ExternalBindingTransfer::class, $constructor->getParameters()[0]->getType()?->getName());
        $source = (string) file_get_contents((new ReflectionClass($adapter))->getFileName());
        self::assertStringNotContainsString('Db::', $source);
        self::assertStringNotContainsString('external_channel_binding', $source);
        $root = dirname(__DIR__, 3);
        $manifest = json_decode((string) file_get_contents($root . '/server/app/modules/peanut/integration/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(ExternalBindingTransfer::class, $manifest['contracts']['exports']);
        self::assertInstanceOf(ExternalBindingTransfer::class, $this->app->make(ExternalBindingTransfer::class));
    }

    public function testExportKeepsTenantScopeAndOnlySecretReferences(): void
    {
        $this->apply($this->value('alpha'));
        $this->apply($this->value('beta'), tenantId: 2);
        $adapter = $this->app->make(ExternalBindingConfigurationAdapter::class);
        $entries = $adapter->export($this->actor());
        self::assertCount(1, $entries);
        self::assertSame('alpha', $entries[0]['value']['config']['app_id']);
        self::assertArrayHasKey('$secret', $entries[0]['value']['config']['api_secret']);
        $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-test-secret', $encoded);
        self::assertStringNotContainsString('callback_key', $encoded);
        self::assertStringNotContainsString($this->rows()[0]['callback_key'], $encoded);
    }

}
