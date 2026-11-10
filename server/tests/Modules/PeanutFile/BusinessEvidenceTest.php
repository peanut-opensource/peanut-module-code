<?php

declare(strict_types=1);

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use BusinessEvidenceFixture\Drivers;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Modules\File\Contract\Dto\BusinessEvidenceCommand;
use PeanutAdmin\Modules\File\Contract\Dto\BusinessEvidenceFile;
use PeanutAdmin\Modules\File\Contract\Dto\BusinessEvidenceFact;
use PeanutAdmin\Modules\File\Contract\Dto\UploadFile;
use PeanutAdmin\Modules\File\Service\FileAuthorizationService;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BusinessEvidenceTest extends TestCase
{
    private StorageService $storage;
    private Drivers $drivers;
    private BusinessEvidenceFixture\Audit $audit;
    private ExecutionContextStore $contexts;
    private TenantContext $actor;
    private string $root;
    private string $server;
    private array $deniedPermissions = [];
    private bool $businessAllowed = true;
    private bool $moduleEnabled = true;
    private int $liveRevision = 1;
    private int $liveTenantRevision = 1;

    protected function setUp(): void
    {
        $this->server = dirname(__DIR__, 3);
        require_once __DIR__ . '/BusinessEvidenceSupport.php';
        require_once __DIR__ . '/../Peanut/File/SnapshotRecoveryReceiptSupport.php';
        class_alias(Drivers::class, 'PeanutAdmin\\Modules\\File\\Composition\\Storage\\StorageDriverFactory');
        class_alias(BusinessEvidenceFixture\Audit::class, 'app\\common\\services\\audit\\AuditContractHost');
        class_alias(BusinessEvidenceFixture\Directory::class, 'PeanutAdmin\\Modules\\Identity\\Contract\\AdminDirectoryQuery');
        $server = $this->server;
        spl_autoload_register(static function (string $class) use ($server): void {
            $prefix = 'PeanutAdmin\\Modules\\File\\';
            if (str_starts_with($class, $prefix)) {
                $path = $server . '/app/modules/peanut/file/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($path)) require_once $path;
            }
        }, true, true);
        self::assertSame(realpath($server . '/app/modules/peanut/file/src/Service/Storage/StorageService.php'), (new ReflectionClass(StorageService::class))->getFileName());
        self::assertSame(realpath($server . '/app/modules/peanut/file/src/Service/FileAuthorizationService.php'), (new ReflectionClass(FileAuthorizationService::class))->getFileName());
        $this->root = sys_get_temp_dir() . '/peanut-business-evidence-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        $this->contexts = new ExecutionContextStore();
        $this->actor = $this->actor();
        $this->connect();
        SnapshotRecoveryReceiptFixture\schema($server . '/app/modules/peanut/file/database/migrations/20261101010003_initial_file_schema.php',
            ['storage_account', 'storage_space', 'storage_route', 'file_object', 'file_immutable_snapshot', 'file_reference', 'file', 'file_derivative']);
        // SQLite RTRIM reproduces ascii_bin's PAD SPACE equality for the original reference identity.
        $referenceSchema = Db::query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'file_reference'")[0]['sql'];
        $referenceSchema = preg_replace('/(`(?:file_key|owner_module_key|owner_type|owner_id)` TEXT)/', '$1 COLLATE RTRIM', $referenceSchema);
        Db::execute('DROP TABLE file_reference');
        Db::execute($referenceSchema);
        $migration = $server . '/app/modules/peanut/file/database/migrations/20261111010003_file_business_evidence.php';
        SnapshotRecoveryReceiptFixture\schema($migration, ['file_evidence_command', 'file_evidence_fact']);
        // Preserve composite primary-key uniqueness omitted by the existing SQLite translator.
        preg_match_all('/CREATE TABLE `pa_([^`]+)` \((.*?)\n\) ENGINE/s', file_get_contents($migration), $tables, PREG_SET_ORDER);
        foreach ($tables as $table) {
            preg_match('/PRIMARY KEY \(([^)]+)\)/', $table[2], $primary);
            Db::execute('CREATE UNIQUE INDEX pk_' . $table[1] . ' ON ' . $table[1] . ' (' . $primary[1] . ')');
        }
        Db::execute('CREATE TABLE business_upload (command_key TEXT PRIMARY KEY, file_key TEXT, status TEXT, digest TEXT)');
        Db::execute('CREATE TABLE business_fact (owner_key TEXT PRIMARY KEY, digest TEXT)');
        Db::execute('CREATE TABLE business_outbox (command_key TEXT PRIMARY KEY, file_key TEXT)');
        $sessions = $this->createStub(PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess::class);
        $sessions->method('revalidateSession')->willReturnCallback(fn(int $tenant, int $account, int $member, string $session, string $client, string $request): TenantContext =>
            $this->actor($tenant, $account, $member, $this->liveRevision, $this->liveTenantRevision));
        $authorization = $this->createStub(AdminAuthorizationQuery::class);
        $authorization->method('principal')->willReturnCallback(fn(TenantContext $context): AdminPrincipal => AdminPrincipal::fromArray([
            'id' => $context->memberId, 'account_id' => $context->accountId, 'tenant_id' => $context->tenantId]));
        $authorization->method('decide')->willReturnCallback(fn($context, $principal, string $permission): PermissionDecision =>
            in_array($permission, $this->deniedPermissions, true) ? PermissionDecision::deny($permission, 'synthetic role revoked') : PermissionDecision::allow($permission));
        $modules = $this->createStub(ModuleRuntimeRepository::class);
        $modules->method('installation')->willReturn(new ModuleInstallationRecord('peanut.file', '1.0.45', 'active', 1, str_repeat('a', 64)));
        $modules->method('tenantModule')->willReturnCallback(fn(int $tenant, string $key): TenantModuleRecord =>
            new TenantModuleRecord($tenant, $key, $this->moduleEnabled ? 'enabled' : 'disabled', null, null, 1));
        $db = think\Container::getInstance()->make(think\DbManager::class);
        $app = new think\App($this->root . '/');
        $app->instance(think\DbManager::class, $db);
        $registry = new PeanutAdmin\Modules\File\Service\FileDocumentReferenceRegistry($app, null);
        $fileAuthorization = new FileAuthorizationService($authorization, $sessions, $modules, $this->contexts, $registry);
        $directory = new BusinessEvidenceFixture\Directory();
        $this->drivers = new Drivers($this->root);
        $this->audit = new BusinessEvidenceFixture\Audit();
        $capabilities = (new ReflectionClass(PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService::class))->newInstanceWithoutConstructor();
        $this->storage = new StorageService($this->drivers, str_repeat('s', 32), 'https://synthetic.invalid', $directory, $fileAuthorization,
            new CurrentExecutionContext($this->contexts), $this->audit, $capabilities, $registry);
        Db::name('storage_account')->insert(['id' => 1, 'account_key' => 'synthetic-local', 'driver' => 'local', 'name' => 'test', 'status' => 'active']);
        Db::name('storage_space')->insert(['id' => 1, 'space_key' => 'synthetic-private', 'name' => 'test', 'account_id' => 1,
            'access_type' => 'private', 'local_path' => 'private/storage', 'status' => 'active']);
        Db::name('storage_route')->insert(['route_key' => 'default.private', 'access_type' => 'private', 'space_id' => 1]);
        file_put_contents($this->root . '/source.txt', 'SYNTHETIC supplier payment evidence');
    }

    private function connect(): void
    {
        $db = new think\DbManager();
        $db->setConfig(['default' => 'evidence', 'connections' => ['evidence' => [
            'type' => 'sqlite', 'database' => $this->root . '/isolated.sqlite', 'prefix' => '', 'fields_cache' => false]]]);
        think\Container::getInstance()->instance(think\DbManager::class, $db);
        $db->query('SELECT 1');
        $db->connect()->getPdo()->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => gmdate('Y-m-d H:i:s'), 1);
        $db->connect()->getPdo()->exec('PRAGMA busy_timeout=3000');
        $contexts = $this->contexts;
        think\Model::maker(static function (think\Model $model) use ($db, $contexts): void {
            $model->setOption('db', $db);
            if ($model instanceof app\common\model\TenantOwnedModel) {
                $model->setDataScopePolicy(new class($contexts) implements app\common\tenancy\DataScopePolicy {
                    public function __construct(private ExecutionContextStore $contexts) {}
                    public function applyTo(think\db\BaseQuery $query): void { $query->where('tenant_id', $this->contexts->require()->tenantId()); }
                    public function prepareWrite(think\Model $row): void
                    {
                        if ((int) $row->tenant_id !== $this->contexts->require()->tenantId()) throw new LogicException('Synthetic tenant mismatch');
                    }
                });
            }
        });
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) return;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    private function actor(int $tenant = 1, int $account = 101, int $member = 11, int $revision = 1, int $tenantRevision = 1): TenantContext
    {
        $actor = (new ReflectionClass(TenantContext::class))->newInstanceWithoutConstructor();
        foreach (['tenantId' => $tenant, 'accountId' => $account, 'memberId' => $member, 'sessionKey' => 'synthetic-session',
            'clientKey' => 'admin-web', 'requestId' => 'evidence-focus', 'issuedAt' => new DateTimeImmutable(),
            'authorizationRevision' => $revision, 'tenantAuthorizationRevision' => $tenantRevision] as $name => $value) {
            (new ReflectionProperty($actor, $name))->setValue($actor, $value);
        }
        return $actor;
    }

    private function within(Closure $operation, ?TenantContext $actor = null): mixed
    {
        return $this->contexts->run(new AdminExecutionContext($actor ?? $this->actor, 'business.evidence'), $operation);
    }

    private function command(string $key = 'upload-1', string $digest = 'a'): BusinessEvidenceCommand
    {
        return new BusinessEvidenceCommand('dcs.settlement', $key, str_repeat($digest, 64));
    }

    private function file(): UploadFile { return new UploadFile($this->root . '/source.txt', 'receipt.txt', 999, 'application/untrusted', 'exe'); }

    private function authority(): Closure
    {
        return function (): void {
            self::assertTrue(Db::connect()->getPdo()->inTransaction());
            if (!$this->businessAllowed) throw BusinessException::forbidden('BUSINESS_SCOPE_DENIED', 'Synthetic financial scope revoked');
        };
    }

    private function bind(BusinessEvidenceCommand $command): Closure
    {
        return function (BusinessEvidenceFile $file) use ($command): void {
            self::assertSame('reserved', $file->state);
            Db::name('business_upload')->insert(['command_key' => $command->commandKey, 'file_key' => $file->fileKey,
                'status' => 'reserved', 'digest' => $command->inputDigest]);
        };
    }

    private function publish(BusinessEvidenceCommand $command): Closure
    {
        return function (BusinessEvidenceFile $file) use ($command): void {
            self::assertSame('published', $file->state);
            self::assertSame(1, Db::name('business_upload')->where('command_key', $command->commandKey)->where('file_key', $file->fileKey)
                ->where('digest', $command->inputDigest)->where('status', 'reserved')->update(['status' => 'published']));
            Db::name('business_outbox')->insert(['command_key' => $command->commandKey, 'file_key' => $file->fileKey]);
        };
    }

    private function store(?BusinessEvidenceCommand $command = null): BusinessEvidenceFile
    {
        $command ??= $this->command();
        return $this->storage->storeEvidence($this->actor, $command, $this->file(), $this->authority(), $this->bind($command), $this->publish($command));
    }

    private function observe(?BusinessEvidenceCommand $command = null): ?BusinessEvidenceFile
    {
        $command ??= $this->command();
        return $this->storage->observeEvidence($this->actor, $command, $this->authority(), $this->publish($command));
    }

    private function denied(Closure $operation, string $code): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (BusinessException $error) { self::assertSame($code, $error->errorCode); }
    }

    private function path(string $key): string
    {
        return $this->root . '/private/storage/' . Db::name('file_object')->where('file_key', $key)->value('object_key');
    }

    public function testTrustedMetadataAndIdempotentOriginalKey(): void
    {
        $this->within(function (): void {
            $metadata = $this->storage->inspectEvidence($this->file());
            self::assertSame('prepared', $metadata->state);
            self::assertNull($metadata->fileKey);
            self::assertSame('text/plain', $metadata->verifiedMimeType);
            self::assertSame(filesize($this->file()->path), $metadata->sizeBytes);
            $first = $this->store();
            self::assertEquals($first, $this->store());
            self::assertEquals($first, $this->observe());
            self::assertSame(1, $this->drivers->puts);
            self::assertSame(1, Db::name('file_object')->count());
            self::assertSame(1, Db::name('business_outbox')->count());
            self::assertSame('published', Db::name('business_upload')->value('status'));
            $this->denied(fn() => $this->store($this->command(digest: 'b')), 'FILE_EVIDENCE_COMMAND_CONFLICT');
            file_put_contents($this->file()->path, 'different bytes');
            $this->denied(fn() => $this->store(), 'FILE_EVIDENCE_COMMAND_CONFLICT');
            self::assertSame(1, $this->drivers->puts);
        });
    }

    public function testUnknownAcknowledgementObservesAndPublishesSameBytesOnce(): void
    {
        $this->within(function (): void {
            $this->drivers->failure = 'after';
            $reserved = $this->store();
            self::assertSame('reserved', $reserved->state);
            self::assertSame('reserved', Db::name('business_upload')->value('status'));
            self::assertSame(0, Db::name('business_outbox')->count());
            self::assertFileExists($this->path($reserved->fileKey));
            $this->drivers->failure = '';
            $published = $this->observe();
            self::assertSame($reserved->fileKey, $published->fileKey);
            self::assertSame('published', $published->state);
            self::assertSame(1, $this->drivers->puts);
            self::assertSame(1, Db::name('business_outbox')->count());
        });
    }

    public function testUnknownAbsentNeverRewritesAndCanObserveDelayedOriginalWrite(): void
    {
        $this->within(function (): void {
            self::assertNull($this->observe());
            $this->drivers->failure = 'before';
            $reserved = $this->store();
            self::assertEquals($reserved, $this->store());
            self::assertEquals($reserved, $this->observe());
            self::assertSame(1, $this->drivers->puts);
            $path = $this->path($reserved->fileKey);
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
            copy($this->file()->path, $path); // Delayed completion at the original provider key.
            self::assertSame($reserved->fileKey, $this->observe()->fileKey);
            self::assertSame('published', $this->observe()->state);
            self::assertSame(1, $this->drivers->puts);
        });
    }

    public function testBindFailureRollsBackEveryReservationBeforeProviderIo(): void
    {
        $this->within(function (): void {
            $command = $this->command();
            try {
                $this->storage->storeEvidence($this->actor, $command, $this->file(), $this->authority(), function ($file) use ($command): void {
                    ($this->bind($command))($file);
                    throw new RuntimeException('Synthetic bind CAS failure');
                }, $this->publish($command));
                self::fail('Expected bind failure');
            } catch (RuntimeException $error) { self::assertSame('Synthetic bind CAS failure', $error->getMessage()); }
            foreach (['file_object','file_evidence_command','file_reference','business_upload'] as $table) self::assertSame(0, Db::name($table)->count());
            self::assertSame(0, $this->drivers->puts);
        });
    }

    public function testPublicationFailureRollsBackFileBusinessAndOutboxThenRecovers(): void
    {
        $this->within(function (): void {
            $this->audit->failPublication = true;
            try { $this->store(); self::fail('Expected audit failure'); }
            catch (RuntimeException $error) { self::assertSame('Synthetic audit persistence failure', $error->getMessage()); }
            $key = Db::name('file_evidence_command')->value('file_key');
            self::assertFileExists($this->path($key));
            self::assertSame('pending_write', Db::name('file_object')->value('status'));
            self::assertSame('reserved', Db::name('business_upload')->value('status'));
            self::assertSame(0, Db::name('business_outbox')->count());
            $this->audit->failPublication = false;
            self::assertSame($key, $this->observe()->fileKey);
            self::assertSame(1, $this->drivers->puts);
        });
    }

    public function testTransactionsAndBusinessAuthorityAreMandatory(): void
    {
        $this->within(function (): void {
            Db::transaction(function (): void {
                $this->denied(fn() => $this->store(), 'FILE_EVIDENCE_TRANSACTION_INVALID');
                $this->denied(fn() => $this->observe(), 'FILE_EVIDENCE_TRANSACTION_INVALID');
            });
            $this->businessAllowed = false;
            $this->denied(fn() => $this->store(), 'BUSINESS_SCOPE_DENIED');
            self::assertSame(0, $this->drivers->puts);
            $this->businessAllowed = true;
            $this->drivers->duringPut = function (): void { $this->businessAllowed = false; };
            $this->denied(fn() => $this->store(), 'BUSINESS_SCOPE_DENIED');
            self::assertSame('reserved', Db::name('business_upload')->value('status'));
            self::assertSame('pending_write', Db::name('file_object')->value('status'));
            $this->businessAllowed = true;
            self::assertSame('published', $this->observe()->state);
        });
    }

    public function testLiveFileRoleModuleAndRevisionAreEnforced(): void
    {
        $this->within(function (): void {
            $this->deniedPermissions = ['peanut.file.upload.file'];
            $this->denied(fn() => $this->store(), 'FILE_ACCESS_DENIED');
            $this->deniedPermissions = [];
            $this->liveRevision = 2;
            $this->denied(fn() => $this->store(), 'FILE_AUTHORIZATION_STALE');
            $this->liveRevision = 1;
            $this->liveTenantRevision = 2;
            $this->denied(fn() => $this->store(), 'FILE_AUTHORIZATION_STALE');
            $this->liveTenantRevision = 1;
            $this->moduleEnabled = false;
            try { $this->store(); self::fail('Expected disabled module'); }
            catch (PeanutAdmin\Kernel\Module\ModuleException $error) { self::assertSame('MODULE_TENANT_DISABLED', $error->errorCode); }
            self::assertSame(0, $this->drivers->puts);
            $this->moduleEnabled = true;
            $this->drivers->duringPut = function (): void { $this->deniedPermissions = ['peanut.file.upload.file']; };
            $this->denied(fn() => $this->store(), 'FILE_ACCESS_DENIED');
            self::assertSame('pending_write', Db::name('file_object')->value('status'));
        });
    }

    public function testCrossTenantAndOtherActorCannotObserveOrRetainOriginal(): void
    {
        $file = $this->within(fn() => $this->store());
        $otherTenant = $this->actor(2);
        $this->within(function () use ($otherTenant, $file): void {
            $this->denied(fn() => $this->storage->observeEvidence($otherTenant, $this->command(), $this->authority(), $this->publish($this->command())), 'FILE_EVIDENCE_CONTEXT_MISMATCH');
            $this->denied(fn() => Db::transaction(fn() => $this->storage->retainEvidence($otherTenant,
                new BusinessEvidenceFact('dcs.settlement', 'payment', 'fact-1', str_repeat('b', 64), [$file->fileKey]), static function (): void {})), 'FILE_EVIDENCE_CONTEXT_MISMATCH');
        });
        $this->within(function () use ($otherTenant, $file): void {
            self::assertNull($this->storage->observeEvidence($otherTenant, $this->command(), $this->authority(), static function (): void { self::fail('Cross tenant publication'); }));
            $this->denied(fn() => Db::transaction(fn() => $this->storage->retainEvidence($otherTenant,
                new BusinessEvidenceFact('dcs.settlement', 'payment', 'fact-1', str_repeat('b', 64), [$file->fileKey]), static function (): void {})), 'FILE_EVIDENCE_NOT_FOUND');
        }, $otherTenant);
        $otherActor = $this->actor(account: 102, member: 12);
        $this->within(function () use ($otherActor): void {
            $this->denied(fn() => $this->storage->observeEvidence($otherActor, $this->command(), $this->authority(), static function (): void {}), 'FILE_EVIDENCE_OWNER_MISMATCH');
        }, $otherActor);
    }

    public function testChangedUnknownBytesCannotPublish(): void
    {
        $this->within(function (): void {
            $this->drivers->failure = 'after';
            $file = $this->store();
            file_put_contents($this->path($file->fileKey), 'tampered');
            $this->denied(fn() => $this->observe(), 'FILE_BYTES_CHANGED');
            self::assertSame('pending_write', Db::name('file_object')->value('status'));
            self::assertSame(0, Db::name('business_outbox')->count());
        });
    }

    private function evidenceLedger(): array
    {
        $rows = [];
        foreach (['file_evidence_command', 'file_object', 'file_reference', 'business_upload', 'business_outbox'] as $table) {
            $rows[$table] = Db::name($table)->select()->toArray();
        }
        return $rows;
    }

    public static function damagedUploadRoots(): iterable
    {
        foreach (['reserved', 'published'] as $state) {
            foreach ([
                'missing' => null,
                'tenant' => ['tenant_id' => 2],
                'file' => ['file_key' => 'file_' . str_repeat('f', 32)],
                'module' => ['owner_module_key' => 'other.module'],
                'type' => ['owner_type' => 'evidence-fact:payment'],
                'command' => ['owner_id' => 'another-command'],
                'digest' => ['reference_digest' => str_repeat('b', 64)],
                'digest-case' => ['reference_digest' => str_repeat('A', 64)],
                'padded-file' => ['file_key' => 'original '],
                'padded-module' => ['owner_module_key' => 'dcs.settlement '],
                'padded-type' => ['owner_type' => 'evidence-upload '],
                'padded-command' => ['owner_id' => 'upload-1 '],
            ] as $damage => $changes) {
                yield $state . '-' . $damage => [$state, $changes];
            }
        }
    }

    #[DataProvider('damagedUploadRoots')]
    public function testMissingOrTamperedUploadRootRejectsObservationAndRetryWithoutRepair(string $state, ?array $changes): void
    {
        $this->within(function () use ($state, $changes): void {
            $this->drivers->failure = $state === 'reserved' ? 'after' : '';
            $file = $this->store();
            self::assertSame($state, $file->state);
            $root = Db::name('file_reference')->where('file_key', $file->fileKey)->find();
            self::assertNotNull($root);
            self::assertSame($this->command()->inputDigest, $root['reference_digest']);
            if (($changes['file_key'] ?? null) === 'original ') $changes['file_key'] = $file->fileKey . ' ';
            $query = Db::name('file_reference')->where('id', $root['id']);
            self::assertSame(1, $changes === null ? $query->delete() : $query->update($changes));
            $before = $this->evidenceLedger();
            $bytes = file_get_contents($this->path($file->fileKey));
            $this->denied(fn() => $this->observe(), 'FILE_EVIDENCE_OBJECT_CHANGED');
            $this->denied(fn() => $this->store(), 'FILE_EVIDENCE_OBJECT_CHANGED');
            self::assertSame($before, $this->evidenceLedger());
            self::assertSame($bytes, file_get_contents($this->path($file->fileKey)));
            self::assertSame(1, $this->drivers->puts);
        });
    }

    private function concurrentEvidenceOperation(Closure $operation): void
    {
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
        $child = pcntl_fork();
        if ($child === -1) throw new RuntimeException('Unable to fork isolated evidence worker');
        if ($child === 0) {
            try {
                $this->connect();
                $operation();
                exit(0);
            } catch (Throwable $error) {
                file_put_contents($this->root . '/reference-worker-error', $error::class . ': ' . $error->getMessage());
                exit(1);
            }
        }
        pcntl_waitpid($child, $status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status), is_file($this->root . '/reference-worker-error')
            ? file_get_contents($this->root . '/reference-worker-error') : 'worker');
    }

    public static function concurrentRootDamage(): iterable
    {
        yield 'deleted' => [null];
        yield 'digest-changed' => [['reference_digest' => str_repeat('b', 64)]];
    }

    #[DataProvider('concurrentRootDamage')]
    public function testConcurrentUploadRootDamageDuringInspectionPreventsPublication(?array $changes): void
    {
        $this->within(function () use ($changes): void {
            $this->drivers->failure = 'after';
            $file = $this->store();
            $beforePublication = null;
            $this->drivers->duringInspect = function () use ($changes, &$beforePublication): void {
                $this->concurrentEvidenceOperation(function () use ($changes): void {
                    $query = Db::name('file_reference')->where('owner_type', 'evidence-upload');
                    $changed = $changes === null ? $query->delete() : $query->update($changes);
                    if ($changed !== 1) throw new RuntimeException('Original reference was not changed');
                });
                $beforePublication = $this->evidenceLedger();
            };
            $this->denied(fn() => $this->observe(), 'FILE_EVIDENCE_OBJECT_CHANGED');
            self::assertNotNull($beforePublication);
            self::assertSame($beforePublication, $this->evidenceLedger());
            self::assertSame('pending_write', Db::name('file_object')->value('status'));
            self::assertSame($file->fileKey, Db::name('file_evidence_command')->value('file_key'));
            self::assertSame(0, Db::name('business_outbox')->count());
            self::assertSame(1, $this->drivers->puts);
        });
    }

    public function testConcurrentSuccessfulObservationPreservesOriginalRootAndPublishesOnce(): void
    {
        $this->within(function (): void {
            $this->drivers->failure = 'after';
            $file = $this->store();
            $root = Db::name('file_reference')->where('file_key', $file->fileKey)->find();
            self::assertNotNull($root);
            $this->drivers->duringInspect = function (): void {
                $this->drivers->duringInspect = null;
                $this->concurrentEvidenceOperation(function (): void {
                    if ($this->observe()->state !== 'published') throw new RuntimeException('Concurrent observation did not publish');
                });
            };
            self::assertSame($file->fileKey, $this->observe()->fileKey);
            self::assertSame('published', $this->store()->state);
            self::assertSame($root, Db::name('file_reference')->where('file_key', $file->fileKey)->find());
            self::assertSame(1, Db::name('file_object')->count());
            self::assertSame(1, Db::name('business_outbox')->count());
            self::assertSame(1, $this->drivers->puts);
            $this->denied(fn() => $this->storage->delete(1, $file->fileKey), 'FILE_REFERENCED');
        });
    }

    public function testForeignTenantRootAndLaterSuccessCannotRepairBrokenOriginal(): void
    {
        $file = $this->within(function (): BusinessEvidenceFile {
            $this->drivers->failure = 'after';
            $file = $this->store();
            Db::name('file_reference')->where('file_key', $file->fileKey)->update(['tenant_id' => 2]);
            return $file;
        });
        $otherTenant = $this->actor(2);
        $before = $this->evidenceLedger();
        $this->within(function () use ($otherTenant): void {
            self::assertNull($this->storage->observeEvidence($otherTenant, $this->command(), $this->authority(),
                static function (): void { self::fail('Foreign tenant published the original'); }));
        }, $otherTenant);
        self::assertSame($before, $this->evidenceLedger());
        $this->within(function () use ($file): void {
            $this->denied(fn() => $this->observe(), 'FILE_EVIDENCE_OBJECT_CHANGED');
            $foreignRoot = Db::name('file_reference')->where('file_key', $file->fileKey)->find();
            $originalCommand = Db::name('file_evidence_command')->where('command_key', 'upload-1')->find();
            $originalObject = Db::name('file_object')->where('file_key', $file->fileKey)->find();
            $this->drivers->failure = '';
            $later = $this->store($this->command('upload-2'));
            self::assertSame('published', $later->state);
            self::assertNotSame($file->fileKey, $later->fileKey);
            $this->denied(fn() => $this->store(), 'FILE_EVIDENCE_OBJECT_CHANGED');
            self::assertSame($originalCommand, Db::name('file_evidence_command')->where('command_key', 'upload-1')->find());
            self::assertSame($originalObject, Db::name('file_object')->where('file_key', $file->fileKey)->find());
            self::assertSame($foreignRoot, Db::name('file_reference')->where('file_key', $file->fileKey)->find());
            self::assertSame(0, Db::name('file_reference')->where('tenant_id', 1)->where('file_key', $file->fileKey)->count());
            self::assertSame(2, $this->drivers->puts);
            self::assertSame(1, Db::name('business_outbox')->count());
        });
    }

    private function retain(BusinessEvidenceFact $fact): void
    {
        $this->storage->retainEvidence($this->actor, $fact, function (array $files) use ($fact): void {
            ($this->authority())();
            self::assertSame($fact->basisDigest, Db::name('business_fact')->where('owner_key', $fact->ownerKey)->value('digest'));
            self::assertSame($fact->fileKeys, array_column($files, 'fileKey'));
        });
    }

    public function testFactReferenceIsImmutableIdempotentAndDeletionProtected(): void
    {
        $this->within(function (): void {
            $file = $this->store();
            $other = $this->store($this->command('upload-2'));
            $fact = new BusinessEvidenceFact('dcs.settlement', 'payment', 'fact-1', str_repeat('b', 64), [$file->fileKey]);
            $this->denied(fn() => $this->retain($fact), 'FILE_EVIDENCE_TRANSACTION_INVALID');
            Db::transaction(function () use ($fact): void {
                Db::name('business_fact')->insert(['owner_key' => $fact->ownerKey, 'digest' => $fact->basisDigest]);
                $this->retain($fact);
                $this->retain($fact);
            });
            self::assertSame(1, Db::name('file_evidence_fact')->count());
            self::assertSame(1, Db::name('file_reference')->where('owner_type', 'evidence-fact:payment')->count());
            $this->denied(fn() => $this->storage->delete(1, $file->fileKey), 'FILE_REFERENCED');
            foreach ([new BusinessEvidenceFact('dcs.settlement', 'payment', 'fact-1', str_repeat('c', 64), [$file->fileKey]),
                new BusinessEvidenceFact('dcs.settlement', 'payment', 'fact-1', str_repeat('b', 64), [$other->fileKey])] as $changed) {
                $this->denied(fn() => Db::transaction(function () use ($changed): void {
                    Db::name('business_fact')->where('owner_key', 'fact-1')->update(['digest' => $changed->basisDigest]);
                    $this->retain($changed);
                }), 'FILE_EVIDENCE_FACT_IMMUTABLE');
            }
            self::assertSame($fact->basisDigest, Db::name('business_fact')->value('digest'));
            $this->deniedPermissions = ['peanut.file.download-original'];
            $this->denied(fn() => Db::transaction(fn() => $this->retain($fact)), 'FILE_ACCESS_DENIED');
            self::assertFileExists($this->path($file->fileKey));
        });
    }

    public function testFactAndReferenceRollbackTogether(): void
    {
        $this->within(function (): void {
            $file = $this->store();
            $fact = new BusinessEvidenceFact('dcs.settlement', 'payment', 'fact-rollback', str_repeat('c', 64), [$file->fileKey]);
            try {
                Db::transaction(function () use ($fact): void {
                    Db::name('business_fact')->insert(['owner_key' => $fact->ownerKey, 'digest' => $fact->basisDigest]);
                    $this->retain($fact);
                    throw new RuntimeException('Synthetic financial transaction rollback');
                });
                self::fail('Expected financial rollback');
            } catch (RuntimeException $error) { self::assertSame('Synthetic financial transaction rollback', $error->getMessage()); }
            self::assertSame(0, Db::name('business_fact')->count());
            self::assertSame(0, Db::name('file_evidence_fact')->count());
            self::assertSame(0, Db::name('file_reference')->where('owner_type', 'evidence-fact:payment')->count());
        });
    }

    public function testConcurrentCallerDuringProviderWriteOnlyObservesOriginalReservation(): void
    {
        $this->within(function (): void {
            $command = $this->command();
            $this->drivers->duringPut = function (string $objectKey) use ($command): void {
                self::assertFalse(Db::connect()->getPdo()->inTransaction());
                $child = pcntl_fork();
                if ($child === -1) throw new RuntimeException('Unable to fork isolated concurrency worker');
                if ($child === 0) {
                    try {
                        $this->connect();
                        $result = $this->store($command);
                        file_put_contents($this->root . '/concurrent-result.json', json_encode(['key' => $result->fileKey, 'state' => $result->state, 'puts' => $this->drivers->puts], JSON_THROW_ON_ERROR));
                        exit(0);
                    } catch (Throwable $error) {
                        file_put_contents($this->root . '/concurrent-error', $error::class . ': ' . $error->getMessage());
                        exit(1);
                    }
                }
                pcntl_waitpid($child, $status);
                self::assertSame(0, pcntl_wexitstatus($status), is_file($this->root . '/concurrent-error') ? file_get_contents($this->root . '/concurrent-error') : 'worker');
                $result = json_decode(file_get_contents($this->root . '/concurrent-result.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('reserved', $result['state']);
                self::assertSame(1, $result['puts']); // Inherited count; child made no additional PUT.
                self::assertSame($result['key'], Db::name('file_evidence_command')->value('file_key'));
            };
            $file = $this->store($command);
            self::assertSame('published', $file->state);
            self::assertSame(1, Db::name('file_object')->count());
            self::assertSame(1, Db::name('business_outbox')->count());
        });
    }

    public function testExistingPrivateStorageAndDocumentMetadataRemainUsable(): void
    {
        $this->within(function (): void {
            $file = $this->storage->storePath(1, 11, 'document.resource', $this->file()->path, 'document.txt', 'application/untrusted');
            self::assertSame('private', $file['access_type']);
            $snapshot = $this->storage->describeForDocument($this->actor, $file['file_key']);
            self::assertSame(hash_file('sha256', $this->file()->path), $snapshot['sha256']);
            self::assertSame('text/plain', $snapshot['verified_mime_type']);
            $opened = $this->storage->openForTenant(1, $file['file_key']);
            self::assertSame(file_get_contents($this->file()->path), file_get_contents($opened['path']));
            self::assertSame(0, Db::name('file_evidence_command')->count());
        });
    }

    public function testTwoProcessesRacingToReserveNeverCreateTwoKeys(): void
    {
        $children = [];
        for ($worker = 0; $worker < 2; ++$worker) {
            $child = pcntl_fork();
            if ($child === -1) throw new RuntimeException('Unable to fork isolated reservation worker');
            if ($child === 0) {
                try {
                    $this->connect();
                    $deadline = microtime(true) + 5;
                    while (!is_file($this->root . '/start')) {
                        if (microtime(true) > $deadline) throw new RuntimeException('Concurrent start timed out');
                        usleep(1000);
                    }
                    $result = $this->within(fn() => $this->store());
                    $receipt = ['key' => $result->fileKey, 'state' => $result->state, 'puts' => $this->drivers->puts];
                } catch (think\db\exception\PDOException $error) {
                    $code = (int) ($error->getData()['PDO Error Info']['Driver Error Code'] ?? 0);
                    // SQLite serializes writers at database level; no automatic re-PUT on a serialization conflict.
                    $receipt = in_array($code, [5, 6, 517], true) ? ['serialization_conflict' => $code, 'puts' => $this->drivers->puts]
                        : ['error' => $error->getMessage()];
                } catch (Throwable $error) {
                    $receipt = ['error' => $error::class . ': ' . $error->getMessage()];
                }
                file_put_contents($this->root . '/worker-' . $worker . '.json', json_encode($receipt, JSON_THROW_ON_ERROR));
                exit(isset($receipt['error']) ? 1 : 0);
            }
            $children[] = $child;
        }
        touch($this->root . '/start');
        $puts = 0;
        $keys = [];
        foreach ($children as $worker => $child) {
            pcntl_waitpid($child, $status);
            $receipt = json_decode(file_get_contents($this->root . '/worker-' . $worker . '.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, pcntl_wexitstatus($status), $receipt['error'] ?? 'worker');
            $puts += $receipt['puts'];
            if (isset($receipt['key'])) $keys[] = $receipt['key'];
        }
        $this->within(function () use ($puts, $keys): void {
            self::assertSame(1, Db::name('file_evidence_command')->count());
            self::assertSame(1, Db::name('file_object')->count());
            self::assertSame(1, $puts);
            self::assertNotEmpty($keys);
            self::assertCount(1, array_unique($keys));
            $published = $this->observe();
            self::assertSame('published', $published->state);
            self::assertSame($keys[0], $published->fileKey);
            self::assertSame(1, Db::name('business_outbox')->count());
        });
    }
}
