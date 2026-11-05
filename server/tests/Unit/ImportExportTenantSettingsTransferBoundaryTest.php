<?php

declare(strict_types=1);

namespace tests\Unit;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\TenantSettingsConfigurationAdapter;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsTransfer;
use PeanutAdmin\Modules\Settings\ModuleProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** Real ThinkORM on isolated in-memory tables; not MySQL locking or HTTP authorization proof. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ImportExportTenantSettingsTransferBoundaryTest extends TestCase
{
    private PDO $database;
    private App $app;
    private TenantSettingsTransfer $transfer;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporaryRoot = $root . '/.local/tmp/tenant-settings-transfer-tests';
        if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
            throw new \RuntimeException('TRANSFER_TEST_DIRECTORY_UNAVAILABLE');
        }
        $temporary = realpath($temporaryRoot);
        self::assertIsString($temporary);
        self::assertStringStartsWith($root . '/.local/tmp/', $temporary);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($temporary . '/case-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant_setting (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
                namespace TEXT NOT NULL, config_json TEXT NOT NULL, revision INTEGER NOT NULL,
                create_time INTEGER NOT NULL, update_time INTEGER NOT NULL,
                UNIQUE(tenant_id, namespace)
            );
            INSERT INTO pa_tenant_setting VALUES
                (1, 1, 'website', '{"title":"first","password":"synthetic-secret-a"}', 3, 100, 100),
                (2, 2, 'website', '{"title":"second","password":"synthetic-secret-b"}', 9, 200, 200);
            SQL);
        $this->app->bind((new ModuleProvider())->bindings());
        $this->transfer = $this->app->make(TenantSettingsTransfer::class);
        self::assertStringStartsWith($root . '/server/app/', (new \ReflectionClass($this->transfer))->getFileName());
    }

    private function actor(int $tenantId = 1): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $tenantId,
            'synthetic-session-' . $tenantId,
            $tenantId,
            100 + $tenantId,
            200 + $tenantId,
            'admin-web',
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
            3,
            3,
        ), 'settings-transfer-test');
    }

    public function testAdapterCannotAccessSettingsPersistenceDirectly(): void
    {
        $adapter = $this->app->make(TenantSettingsConfigurationAdapter::class);
        $source = file_get_contents((new \ReflectionClass($adapter))->getFileName());
        self::assertIsString($source);
        self::assertStringNotContainsString('think\\facade\\Db', $source);
        self::assertStringNotContainsString('Db::', $source);
        self::assertStringContainsString('Settings\\Contract\\TenantSettingsTransfer', $source);
        $constructor = (new \ReflectionClass($adapter))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(TenantSettingsTransfer::class, (string) $constructor->getParameters()[0]->getType());
    }

    public function testCurrentPreservesExistenceRevisionAndSecretRedaction(): void
    {
        $adapter = $this->app->make(TenantSettingsConfigurationAdapter::class);
        $current = $adapter->current($this->actor(), 'website');
        self::assertTrue($current['exists']);
        self::assertSame(3, $current['revision']);
        self::assertSame('first', $current['value']['title']);
        self::assertSame('configured', $current['value']['password']['$secret']['state']);
        self::assertStringNotContainsString('synthetic-secret-a', json_encode($current, JSON_THROW_ON_ERROR));
        self::assertSame(['exists' => false, 'value' => null, 'revision' => null], $adapter->current($this->actor(), 'missing'));
    }

    public function testExportIsOrderedRedactedAndNeverIncludesAnotherTenant(): void
    {
        $this->database->exec("INSERT INTO pa_tenant_setting VALUES (3, 1, 'agreement', '{\"text\":\"a\"}', 1, 100, 100)");
        $adapter = $this->app->make(TenantSettingsConfigurationAdapter::class);
        $entries = $adapter->export($this->actor());
        self::assertSame(['agreement', 'website'], array_column($entries, 'key'));
        $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-secret-', $encoded);
        self::assertStringNotContainsString('second', $encoded);
        self::assertStringNotContainsString('tenant_id', $encoded);
        self::assertCount(1, $entries[1]['secrets']);
    }

}
