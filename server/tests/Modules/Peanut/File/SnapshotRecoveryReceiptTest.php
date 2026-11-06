<?php

declare(strict_types=1);

use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SnapshotRecoveryReceiptFixture\Audit;
use SnapshotRecoveryReceiptFixture\FileAuthorization;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SnapshotRecoveryReceiptTest extends TestCase
{
    private StorageService $storage;
    private TenantContext $actor;
    private ExecutionContextStore $contexts;
    private FileAuthorization $fileAuthorization;
    private Audit $audit;
    private string $directory;
    private string $source;
    private const BYTES = 'SYNTHETIC 002d snapshot recovery receipt bytes';

    protected function setUp(): void
    {
        require_once __DIR__ . '/SnapshotRecoveryReceiptSupport.php';
        class_alias(FileAuthorization::class, 'PeanutAdmin\\Modules\\File\\Service\\FileAuthorizationService');
        class_alias(SnapshotRecoveryReceiptFixture\Directory::class, 'PeanutAdmin\\Modules\\Identity\\Contract\\AdminDirectoryQuery');
        class_alias(Audit::class, 'app\\common\\services\\audit\\AuditContractHost');
        $server = dirname(__DIR__, 4);
        spl_autoload_register(static function (string $class) use ($server): void {
            foreach (['PeanutAdmin\\Modules\\File\\' => '/app/modules/peanut/file/src/'] as $prefix => $path) {
                if (str_starts_with($class, $prefix)) {
                    $file = $server . $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) require_once $file;
                }
            }
        }, true, true);
        self::assertSame(realpath($server . '/app/modules/peanut/file/src/Service/Storage/StorageService.php'), (new ReflectionClass(StorageService::class))->getFileName());
        $db = new think\DbManager();
        $db->setConfig(['default' => 'native', 'connections' => ['native' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_cache' => false]]]);
        think\Container::getInstance()->instance(think\DbManager::class, $db);
        // SQLite connection compatibility only; production queries and ledger transitions stay intact.
        $db->query('SELECT 1');
        $db->connect()->getPdo()->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => gmdate('Y-m-d H:i:s'), 1);
        think\Model::maker(static function (think\Model $model) use ($db): void {
            $model->setOption('db', $db);
            if ($model instanceof app\common\model\TenantOwnedModel) {
                $model->setDataScopePolicy(new class implements app\common\tenancy\DataScopePolicy {
                    public function applyTo(think\db\BaseQuery $query): void { $query->where('tenant_id', 1); }
                    public function prepareWrite(think\Model $row): void
                    {
                        if ((int) $row->tenant_id !== 1) throw new LogicException('SYNTHETIC_TENANT_MISMATCH');
                    }
                });
            }
        });
        SnapshotRecoveryReceiptFixture\schema($server . '/app/modules/peanut/file/database/migrations/20261101010003_initial_file_schema.php',
            ['storage_account', 'storage_space', 'storage_route', 'file_object', 'file_immutable_snapshot', 'file_reference', 'file', 'file_derivative']);
        // Incremental column, translated for SQLite; MySQL DDL is separately syntax reviewed.
        Db::execute('ALTER TABLE file_object ADD COLUMN snapshot_cleanup_acknowledged_at TEXT DEFAULT NULL');
        $this->actor = (new ReflectionClass(TenantContext::class))->newInstanceWithoutConstructor();
        foreach (['tenantId' => 1, 'accountId' => 101, 'memberId' => 11, 'sessionKey' => 'synthetic-session',
            'clientKey' => 'admin-web', 'requestId' => 'snapshot-recovery-receipt-002d', 'issuedAt' => new DateTimeImmutable(),
            'authorizationRevision' => 1, 'tenantAuthorizationRevision' => 1] as $name => $value) {
            (new ReflectionProperty($this->actor, $name))->setValue($this->actor, $value);
        }
        $this->contexts = new ExecutionContextStore();
        $execution = new CurrentExecutionContext($this->contexts);
        $this->directory = dirname($server) . '/.local/tmp/snapshot-recovery-receipt-002d-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $app = new think\App($this->directory . '/');
        $app->instance(think\DbManager::class, $db);
        $directory = new SnapshotRecoveryReceiptFixture\Directory();
        $unused = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $drivers = new PeanutAdmin\Modules\File\Composition\Storage\StorageDriverFactory(
            new PeanutAdmin\Modules\File\Infrastructure\Storage\FailClosedStorageCredentialResolver(),
            $unused(PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuStorageHttpTransport::class),
            $unused(PeanutAdmin\Modules\File\Composition\Storage\AliyunStorageClientFactory::class),
            $unused(PeanutAdmin\Modules\File\Composition\Storage\QcloudStorageClientFactory::class), $execution, $app);
        $registry = new PeanutAdmin\Modules\File\Service\FileDocumentReferenceRegistry($app, null);
        $this->fileAuthorization = new FileAuthorization();
        $this->audit = new Audit();
        $capabilities = $unused(PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService::class);
        $this->storage = new StorageService($drivers, str_repeat('s', 32), 'https://synthetic.invalid', $directory,
            $this->fileAuthorization, $execution, $this->audit, $capabilities, $registry);
        Db::name('storage_account')->insert(['id' => 1, 'account_key' => 'synthetic-local', 'driver' => 'local', 'name' => '002d', 'status' => 'active']);
        Db::name('storage_space')->insert(['id' => 1, 'space_key' => 'synthetic-private', 'name' => '002d', 'account_id' => 1,
            'access_type' => 'private', 'local_path' => 'private/storage', 'status' => 'active']);
        Db::name('storage_route')->insert(['route_key' => 'default.private', 'access_type' => 'private', 'space_id' => 1]);
        file_put_contents($this->directory . '/source.txt', self::BYTES);
        $this->within(function (): void {
            $this->source = $this->storage->storePath(1, 11, 'document.resource', $this->directory . '/source.txt', 'synthetic.txt', 'text/plain')['file_key'];
        });
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) return;
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->directory);
    }

    private function within(callable $operation): mixed
    {
        return $this->contexts->run(new AdminExecutionContext($this->actor, 'file.snapshot-recovery-receipt-002d'), $operation);
    }

    private function path(string $key): string
    {
        return $this->directory . '/private/storage/' . Db::name('file_object')->where('file_key', $key)->value('object_key');
    }

    private function denied(callable $operation, string $code): void
    {
        try { $operation(); self::fail('Expected rejection: ' . $code); }
        catch (BusinessException $error) { self::assertSame($code, $error->errorCode); }
    }

    private function orphan(bool $aged = true): string
    {
        $key = $this->storage->storePath(1, 11, 'document.snapshot', $this->directory . '/source.txt', 'orphan.txt', 'text/plain')['file_key'];
        if ($aged) Db::name('file_object')->where('file_key', $key)->update(['created_at' => '2000-01-01 00:00:00']);
        return $key;
    }

    public function testOrphanAcknowledgementPersistsAndRepeatedRecoveryStaysComplete(): void
    {
        $this->within(function (): void {
            $key = $this->orphan();
            self::assertSame('interrupted_capture', $this->storage->pendingDocumentSnapshots($this->actor)[0]['status']);
            self::assertSame(0, Db::name('file_immutable_snapshot')->count());
            $result = $this->storage->recoverDocumentSnapshot($this->actor, $key);
            self::assertTrue($result['provider_delete_acknowledged']);
            self::assertFalse($result['cleanup_pending']);
            self::assertFileDoesNotExist($this->path($key));
            self::assertSame('archived', Db::name('file_object')->where('file_key', $key)->value('status'));
            self::assertSame([], $this->storage->pendingDocumentSnapshots($this->actor));
            self::assertNotNull(Db::name('file_object')->where('file_key', $key)->value('snapshot_cleanup_acknowledged_at'));
            $revision = Db::name('file_object')->where('file_key', $key)->value('revision');
            self::assertTrue($this->storage->recoverDocumentSnapshot($this->actor, $key)['provider_delete_acknowledged']);
            self::assertSame($revision, Db::name('file_object')->where('file_key', $key)->value('revision'));
            self::assertSame([], $this->storage->pendingDocumentSnapshots($this->actor));
            self::assertSame(0, Db::name('file_immutable_snapshot')->count());
        });
    }

    public function testLocalProviderRefusalRemainsPendingAndSameKeyRetries(): void
    {
        $this->within(function (): void {
            $key = $this->orphan();
            $path = $this->path($key);
            // Real LocalStorageDriver refuses non-regular objects. No provider or StorageService fake.
            unlink($path);
            mkdir($path);
            $result = $this->storage->recoverDocumentSnapshot($this->actor, $key);
            self::assertFalse($result['provider_delete_acknowledged']);
            self::assertTrue($result['cleanup_pending']);
            self::assertSame('archived', Db::name('file_object')->where('file_key', $key)->value('status'));
            self::assertNull(Db::name('file_object')->where('file_key', $key)->value('snapshot_cleanup_acknowledged_at'));
            self::assertSame($key, $this->storage->pendingDocumentSnapshots($this->actor)[0]['file_key']);
            rmdir($path);
            file_put_contents($path, self::BYTES);
            self::assertTrue($this->storage->recoverDocumentSnapshot($this->actor, $key)['provider_delete_acknowledged']);
            self::assertFileDoesNotExist($path);
            self::assertSame([], $this->storage->pendingDocumentSnapshots($this->actor));
        });
    }

    public function testRecoveryDeniesYoungReferencedClaimedAndRevoked(): void
    {
        $this->within(function (): void {
            $young = $this->orphan(false);
            $this->denied(fn() => $this->storage->recoverDocumentSnapshot($this->actor, $young), 'FILE_SNAPSHOT_RECOVERY_DENIED');
            $key = $this->orphan();
            Db::name('file_reference')->insert(['file_key' => $key, 'tenant_id' => 1]);
            self::assertSame([], $this->storage->pendingDocumentSnapshots($this->actor));
            $this->denied(fn() => $this->storage->recoverDocumentSnapshot($this->actor, $key), 'FILE_SNAPSHOT_RECOVERY_DENIED');
            Db::name('file_reference')->where('file_key', $key)->delete();
            Db::name('file_immutable_snapshot')->insert(['file_key' => $key, 'tenant_id' => 1, 'capture_id' => hash('sha256', $key), 'status' => 'claimed']);
            self::assertSame([], $this->storage->pendingDocumentSnapshots($this->actor));
            $this->denied(fn() => $this->storage->recoverDocumentSnapshot($this->actor, $key), 'FILE_SNAPSHOT_RECOVERY_DENIED');
            $this->fileAuthorization->allowed = false;
            $this->denied(fn() => $this->storage->pendingDocumentSnapshots($this->actor), 'SYNTHETIC_FILE_PERMISSION_REVOKED');
            $this->denied(fn() => $this->storage->recoverDocumentSnapshot($this->actor, $key), 'SYNTHETIC_FILE_PERMISSION_REVOKED');
            self::assertFileExists($this->path($key));
            self::assertFileExists($this->path($young));
        });
    }

    public function testNativeCaptureCleanupStillCompletes(): void
    {
        $this->within(function (): void {
            $source = $this->storage->describeForDocument($this->actor, $this->source);
            $snapshot = $this->storage->captureForDocument($this->actor, $this->source, $source['sha256'], $source['revision'], '002d-normal');
            $key = $snapshot['file_key'];
            Db::name('file_object')->where('file_key', $key)->update(['created_at' => '2000-01-01 00:00:00']);
            self::assertSame('captured', $this->storage->pendingDocumentSnapshots($this->actor)[0]['status']);
            self::assertTrue($this->storage->recoverDocumentSnapshot($this->actor, $key)['provider_delete_acknowledged']);
            self::assertSame('abandoned', Db::name('file_immutable_snapshot')->where('file_key', $key)->value('status'));
            self::assertFileDoesNotExist($this->path($key));
            self::assertSame([], $this->storage->pendingDocumentSnapshots($this->actor));
            self::assertTrue($this->storage->recoverDocumentSnapshot($this->actor, $key)['provider_delete_acknowledged']);
            self::assertSame('abandoned', Db::name('file_immutable_snapshot')->where('file_key', $key)->value('status'));
        });
    }

    public function testPendingScansPastExcludedFirstPage(): void
    {
        $this->within(function (): void {
            $first = $this->orphan();
            $template = Db::name('file_object')->where('file_key', $first)->find();
            for ($i = 1; $i <= 100; ++$i) {
                $row = $template;
                unset($row['id']);
                $row['file_key'] = 'file_' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
                $row['object_key'] = 'tenants/v1/1/excluded-' . $i;
                $row['created_at'] = '1999-01-01 00:00:00';
                Db::name('file_object')->insert($row);
                Db::name('file_immutable_snapshot')->insert(['file_key' => $row['file_key'], 'tenant_id' => 1, 'capture_id' => hash('sha256', $row['file_key']), 'status' => 'claimed']);
            }
            self::assertSame([$first], array_column($this->storage->pendingDocumentSnapshots($this->actor), 'file_key'));
        });
    }

    public function testLostDurableAcknowledgementRetriesWithAlreadyAbsentReceipt(): void
    {
        $this->within(function (): void {
            $key = $this->orphan();
            // Simulate losing the durable result after actual provider deletion, using SQLite only.
            Db::execute("CREATE TRIGGER reject_cleanup_receipt BEFORE UPDATE OF snapshot_cleanup_acknowledged_at ON file_object BEGIN SELECT RAISE(FAIL, 'synthetic receipt persistence failure'); END");
            $result = $this->storage->recoverDocumentSnapshot($this->actor, $key);
            self::assertTrue($result['cleanup_pending']);
            self::assertFalse($result['provider_delete_acknowledged']);
            self::assertFileDoesNotExist($this->path($key));
            self::assertSame($key, $this->storage->pendingDocumentSnapshots($this->actor)[0]['file_key']);
            Db::execute('DROP TRIGGER reject_cleanup_receipt');
            $result = $this->storage->recoverDocumentSnapshot($this->actor, $key);
            self::assertTrue($result['provider_delete_acknowledged']);
            self::assertSame('already-absent', $result['provider_outcome']);
            self::assertSame([], $this->storage->pendingDocumentSnapshots($this->actor));
        });
    }
}
