<?php

declare(strict_types=1);

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use app\common\exception\BusinessException;
use app\common\execution\{AdminExecutionContext,CurrentExecutionContext,ExecutionContextStore,SystemExecutionContext};
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\{AuthorizationDecision,AuthorizedOperationContext};
use PeanutAdmin\Kernel\Module\{ModuleGuard,ModuleInstallationRecord,ModuleRuntimeRepository,TenantModuleRecord};
use PeanutAdmin\Modules\Document\Contract\{DocumentOwnerReference,VersionSnapshot};
use PeanutAdmin\Modules\Document\Service\{DocumentAuthorizationService,DocumentOwnerRegistry,DocumentService};
use PeanutAdmin\Modules\File\Contract\{FileDocumentSourceChecks,FileReferenceCommands};
use PeanutAdmin\Modules\File\Service\{FileAuthorizationService,FileDocumentReferenceRegistry};
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use PeanutAdmin\Modules\Task\Contract\{JobExecution,SourceTaskBinding,TaskJobService,TaskSourceAuthorizations};
use PeanutAdmin\Modules\Task\Infrastructure\Runtime\ThinkPhpTaskJobRuntime;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PHPUnit\Framework\Attributes\{PreserveGlobalState,RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DocumentTaskSourceTest extends TestCase
{
    private think\App $app;
    private ExecutionContextStore $contexts;
    private CurrentExecutionContext $current;
    private SourceGrantFixture\Sessions $sessions;
    private SourceGrantFixture\Worker $worker;
    private ThinkPhpTaskJobRuntime $runtime;
    private TaskJobStore $jobs;
    private DocumentService $documents;
    private StorageService $storage;
    private Custom\Modules\AiAssistant\Service\AiSources $sources;
    private string $directory;
    private int $requests = 0;
    private int $handoffs = 0;
    private array $disabled = [];
    private bool $ownerAllowed = true;
    private bool $fileAllowed = true;
    private AdminAuthorizationQuery $permissionQuery;
    private ModuleRuntimeRepository $moduleRepository;
    private bool $manageAllowed = true;
    private Custom\Modules\AiAssistant\Service\AiTasks $aiTasks;
    private Custom\Modules\AiAssistant\Service\AiRequestStatus $aiRequestStatus;
    private SourceGrantFixture\DataEngine $aiEngine;
    private SourceGrantFixture\ObservedDefinition $observedAi;
    private int $providerCalls = 0;
    private int $reveals = 0;
    private ?Closure $onReveal = null;


    protected function setUp(): void
    {
        $server = dirname(__DIR__, 4);
        require_once $server . '/vendor/autoload.php';
        require_once $server . '/vendor/topthink/framework/src/helper.php';
        spl_autoload_register(static function (string $class) use ($server): void {
            foreach (['PeanutAdmin\\Modules\\Document\\' => 'peanut/document', 'PeanutAdmin\\Modules\\Task\\' => 'peanut/task',
                'PeanutAdmin\\Modules\\File\\' => 'peanut/file', 'PeanutAdmin\\Modules\\Article\\' => 'peanut/article',
                'Custom\\Modules\\AiAssistant\\' => 'custom/ai_assistant'] as $prefix => $module) {
                if (str_starts_with($class, $prefix)) {
                    $file = $server . '/app/modules/' . $module . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            }
        }, true, true);
        require_once __DIR__ . '/DocumentTaskSourceSupport.php';
        class_alias(SourceGrantFixture\Directory::class, 'PeanutAdmin\\Modules\\Identity\\Contract\\AdminDirectoryQuery');
        class_alias(SourceGrantFixture\Entitlements::class, 'PeanutAdmin\\Modules\\Identity\\Module\\TenantEntitlementService');
        class_alias(SourceGrantFixture\Audit::class, 'app\\common\\services\\audit\\AuditContractHost');
        class_alias(SourceGrantFixture\ReadFence::class, 'PeanutAdmin\\Modules\\Identity\\Contract\\TenantAuthorizationReadFence');
        class_alias(SourceGrantFixture\DataEngine::class, 'PeanutAdmin\\DataPermission\\Engine\\DataPermissionEngine');
        $this->directory = dirname($server) . '/.local/tmp/source-grant-007-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->app = new think\App($this->directory . '/');
        $db = new think\DbManager();
        $db->setConfig(['default' => 'source007', 'connections' => ['source007' => ['type' => SourceGrantFixture\Sqlite::class,
            'database' => ':memory:', 'prefix' => 'pa_', 'builder' => \think\db\builder\Sqlite::class, 'fields_cache' => false]]]);
        $this->app->instance(think\DbManager::class, $db);
        $db->query('SELECT 1');
        $pdo = $db->connect()->getPdo();
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => gmdate('Y-m-d H:i:s') . '.000', 1);
        $pdo->sqliteCreateFunction('SOURCE_ADD_SECONDS', static fn(int $seconds): string => gmdate('Y-m-d H:i:s', time() + $seconds) . '.000', 1);
        $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'source007', 0);
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT,TABLE_NAME TEXT,COLUMN_NAME TEXT)');
        SourceGrantFixture\schema(
            $server . '/app/modules/peanut/document/database/migrations/20261102000007_initial_document_schema.php',
            ['document','document_revision','document_state','document_file_ref','document_resource_binding','document_operation'],
        );
        SourceGrantFixture\schema($server . '/app/modules/peanut/document/database/migrations/20261107030007_document_task_source_grants.php', ['document_source_grant']);
        SourceGrantFixture\schema(
            $server . '/app/modules/peanut/task/database/migrations/20261101010006_initial_task_schema.php',
            ['task_job','task_job_attempt','task_job_event'],
        );
        SourceGrantFixture\schema(
            $server . '/app/modules/peanut/file/database/migrations/20261101010003_initial_file_schema.php',
            ['storage_account','storage_space','storage_route','file_object','file_immutable_snapshot','file_reference','file','file_derivative','file_delivery_grant'],
        );
        Db::execute('ALTER TABLE pa_file_object ADD COLUMN snapshot_cleanup_acknowledged_at TEXT DEFAULT NULL');
        Db::execute('CREATE TABLE pa_article (id INTEGER PRIMARY KEY,tenant_id INTEGER,cid INTEGER,title TEXT,desc TEXT,abstract TEXT,image TEXT,author TEXT,content TEXT,
            click_virtual INTEGER,sort INTEGER,update_time INTEGER,create_time INTEGER,delete_time INTEGER DEFAULT 0,is_show INTEGER,
            body_document_id INTEGER,body_revision_id INTEGER,body_source_state_seq INTEGER,body_snapshot_sha256 TEXT)');
        $this->contexts = new ExecutionContextStore();
        $this->current = new CurrentExecutionContext($this->contexts);
        $scope = new app\common\tenancy\MultiTenantDataScopePolicy($this->current);
        think\Model::maker(static function (think\Model $model) use ($db, $scope): void {
            $model->setOption('db', $db);
            if ($model instanceof app\common\model\TenantOwnedModel) {
                $model->setDataScopePolicy($scope);
            }
        });
        $this->sessions = new SourceGrantFixture\Sessions();
        $authorization = $this->createStub(AdminAuthorizationQuery::class);
        $this->permissionQuery = $authorization;
        $authorization->method('principal')->willReturnCallback(static fn(TenantContext $actor) => AdminPrincipal::fromArray([
            'id' => $actor->memberId,'tenant_id' => $actor->tenantId,'account_id' => $actor->accountId,'authorization_revision' => $actor->authorizationRevision]));
        $authorization->method('decide')->willReturnCallback(function ($actor, $principal, string $permission): PermissionDecision {
            $allowed = str_starts_with($permission, 'peanut.file.') ? $this->fileAllowed
                : (str_starts_with($permission, 'peanut.article.') ? $this->ownerAllowed
                : ($permission === 'peanut.task.jobs.manage' ? $this->manageAllowed : true));
            return $allowed ? PermissionDecision::allow($permission) : PermissionDecision::deny($permission, 'PERMISSION_NOT_GRANTED');
        });
        $modules = $this->createStub(ModuleRuntimeRepository::class);
        $this->moduleRepository = $modules;
        $modules->method('installation')->willReturnCallback(static fn(string $key) => new ModuleInstallationRecord($key, '1.0.0', 'active', 1, str_repeat('a', 64)));
        $modules->method('tenantModule')->willReturnCallback(fn(int $tenant, string $key) => new TenantModuleRecord(
            $tenant,
            $key,
            isset($this->disabled[$key]) ? 'disabled' : 'enabled',
            null,
            null,
            1,
        ));
        $directory = new SourceGrantFixture\Directory();
        $fileRegistry = new FileDocumentReferenceRegistry($this->app, null);
        $fileAuth = new FileAuthorizationService($authorization, $this->sessions, $modules, $this->contexts, $fileRegistry);
        $unused = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $drivers = new PeanutAdmin\Modules\File\Composition\Storage\StorageDriverFactory(
            new PeanutAdmin\Modules\File\Infrastructure\Storage\FailClosedStorageCredentialResolver(),
            $unused(PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuStorageHttpTransport::class),
            $unused(PeanutAdmin\Modules\File\Composition\Storage\AliyunStorageClientFactory::class),
            $unused(PeanutAdmin\Modules\File\Composition\Storage\QcloudStorageClientFactory::class),
            $this->current,
            $this->app,
        );
        $tools = $unused(PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService::class);
        $audit = new SourceGrantFixture\Audit();
        $this->storage = new StorageService(
            $drivers,
            str_repeat('s', 32),
            'https://source007.invalid',
            $directory,
            $fileAuth,
            $this->current,
            $audit,
            $tools,
            $fileRegistry,
        );
        $ownerPolicy = new PeanutAdmin\Modules\Article\Service\ArticleDocumentOwnerPolicy($authorization, $this->current);
        $this->app->instance($ownerPolicy::class, $ownerPolicy);
        $owners = new DocumentOwnerRegistry($this->app, ['peanut.article:article' => $ownerPolicy::class]);
        $fence = $unused(PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationReadFence::class);
        $docAuth = new DocumentAuthorizationService($this->sessions, $authorization, $this->contexts, $modules, $owners, $fence);
        $this->documents = new DocumentService(
            $docAuth,
            $this->createStub(FileReferenceCommands::class),
            $this->storage,
            $this->storage,
            $audit,
            $tools,
            $this->contexts,
            $unused(PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry::class),
            $owners,
            $fence,
            $modules,
        );
        $this->jobs = new TaskJobStore();
        $this->worker = new SourceGrantFixture\Worker($this->sessions);
        $this->runtime = new ThinkPhpTaskJobRuntime(
            $this->jobs,
            str_repeat('j', 32),
            $this->contexts,
            $this->current,
            $directory,
            new app\common\infrastructure\module\ModuleExecutionBoundary($this->current, $modules, $directory),
            new ModuleGuard($modules),
            $unused(app\common\services\CrontabCommandService::class),
            static fn() => null,
            [$this->worker],
            1,
            $this->createStub(PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog::class),
            new SourceGrantFixture\Entitlements(),
        );
        $this->app->instance(TaskSourceAuthorizations::class, $this->runtime);
        $this->app->instance(FileDocumentSourceChecks::class, $this->storage);
        $this->sources = new Custom\Modules\AiAssistant\Service\AiSources($this->documents, $this->storage, $this->storage);
        foreach ([DocumentService::class => 'peanut/document/src/Service/DocumentService.php',
            ThinkPhpTaskJobRuntime::class => 'peanut/task/src/Infrastructure/Runtime/ThinkPhpTaskJobRuntime.php',
            StorageService::class => 'peanut/file/src/Service/Storage/StorageService.php'] as $class => $path) {
            self::assertSame(realpath($server . '/app/modules/' . $path), (new ReflectionClass($class))->getFileName());
        }
        Db::name('storage_account')->insert(['id' => 1,'account_key' => 'source-local','driver' => 'local','name' => 'source007','status' => 'active']);
        Db::name('storage_space')->insert(['id' => 1,'space_key' => 'source-private','name' => 'source007','account_id' => 1,
            'access_type' => 'private','local_path' => 'private/storage','status' => 'active']);
        Db::name('storage_route')->insert(['route_key' => 'default.private','access_type' => 'private','space_id' => 1]);
        $this->seedDocument(1, 1);
        $this->seedDocument(2, 2);
    }

    private function admin(int $tenant, callable $call): mixed
    {
        $actor = $this->sessions->actor($tenant);
        return $this->contexts->run(new AdminExecutionContext($actor, 'source.fixture', ['id' => $actor->memberId]), fn() => $call($actor));
    }

    private function seedDocument(int $id, int $tenant, ?array $file = null, bool $reference = false): void
    {
        $structured = ['schemaVersion' => RichTextProjection::SCHEMA,'editorModel' => 'tiptap-prosemirror',
            'content' => ['type' => 'doc','content' => [['type' => 'paragraph','content' => [['type' => 'text','text' => 'Synthetic tenant ' . $tenant . ' fixed source']]]]],'annotations' => []];
        if ($reference) {
            $structured['content']['content'][] = ['type' => 'media','attrs' => ['fileKey' => 'file_' . str_repeat('f', 32),'kind' => 'image']];
        }
        $projection = $file === null ? RichTextProjection::fromStructured($structured)->json : null;
        $kind = $file === null ? 'rich_text' : 'file_resource';
        $key = $file['file_key'] ?? null;
        $sha = $file['sha256'] ?? null;
        $digest = hash('sha256', json_encode([$kind,1,$projection,null,$key,$sha], JSON_THROW_ON_ERROR));
        Db::name('article')->insert(['id' => $id,'tenant_id' => $tenant,'title' => 'Synthetic source ' . $id,'content' => '',
            'delete_time' => 0,'update_time' => 1,'create_time' => 1]);
        Db::name('document')->insert(['id' => $id,'tenant_id' => $tenant,'owner_module_key' => 'peanut.article',
            'owner_object_type' => 'article','owner_object_id' => (string) $id,'slot' => 'body','content_kind' => $kind,
            'schema_version' => '1','status' => 'active','auth_revision' => 1,'state_seq' => 1,'collaboration_enabled' => 0]);
        if ($projection !== null) {
            Db::name('document_state')->insert(['tenant_id' => $tenant,'document_id' => $id,'state_seq' => 1,
                'projected_json' => $projection,'state_sha256' => hash('sha256', $projection),'projection_schema_version' => RichTextProjection::SCHEMA,
                'persisted_at' => gmdate('Y-m-d H:i:s'),'last_store_id' => 'synthetic-source-' . $id]);
        }
        Db::name('document_revision')->insert(['id' => $id,'tenant_id' => $tenant,'document_id' => $id,'revision_no' => 1,'source_state_seq' => 1,
            'content_kind' => $kind,'content_snapshot_json' => $projection,'yjs_snapshot' => null,'snapshot_sha256' => $digest,
            'immutable_file_key' => $key,'immutable_file_sha256' => $sha,'immutable_file_mime_type' => $file['mime'] ?? null]);
    }

    private static function binding(string $key): SourceTaskBinding
    {
        return new SourceTaskBinding($key, 'custom.ai-assistant', 'custom.ai-assistant.task', 'use', 'custom.ai-assistant.generate');
    }

    private function issue(int $tenant = 1, ?int $document = null, ?string $request = null): array
    {
        $document ??= $tenant;
        $request ??= 'source-request-' . ++$this->requests;
        return $this->admin($tenant, function (TenantContext $actor) use ($document, $request): array {
            $version = $this->documents->versionSnapshot($actor, $document, $document);
            $owner = new DocumentOwnerReference('peanut.article', 'article', (string) $document, 'body');
            return Db::transaction(function () use ($actor, $document, $version, $owner, $request): array {
                $job = $this->runtime->publisher($this->worker)->publish(
                    $this->worker->context($actor),
                    $this->worker->taskType(),
                    ['task_id' => $actor->tenantId],
                    'native-' . $request,
                );
                $expires = time() + 3600;
                $receipt = $this->documents->issueTaskSource($actor, $version, $owner, self::binding($job->jobKey), $expires, $request);
                $source = ['document_id' => $version->documentId,'revision_id' => $version->revisionId,'digest' => $version->digest,
                    'content_kind' => $version->contentKind,'structured_content' => $version->structuredContent,
                    'text' => $version->contentKind === 'rich_text' ? 'Synthetic tenant ' . $actor->tenantId . ' fixed source' : '',
                    'file_key' => $version->fileKey,'sha256' => $version->fileSha256,'verified_mime_type' => $version->verifiedMimeType,
                    'owner' => ['module_key' => 'peanut.article','object_type' => 'article','object_id' => (string) $document,'slot' => 'body'],
                    'instruction' => 'Synthetic controlled provider only'];
                return compact('actor', 'version', 'owner', 'job', 'expires', 'receipt', 'source', 'request');
            });
        });
    }

    private function runJob(array $bundle, ?callable $handler = null): PeanutAdmin\Modules\Task\Contract\JobRecord
    {
        $this->worker->error = null;
        $this->worker->entered = 0;
        $this->worker->handler = function (AuthorizedOperationContext $context, JobExecution $execution) use ($bundle, $handler): void {
            if ($handler !== null) {
                $handler($context, $execution);
                return;
            }
            $source = $this->sources->authorizeExternalSend($execution, $bundle['receipt'], $bundle['source'], $this->directory . '/input-audio.bin');
            $execution->checkpoint();
            self::assertInstanceOf(SystemExecutionContext::class, $this->current->current());
            self::assertSame('Synthetic tenant ' . $context->tenantContext->tenantId . ' fixed source', $source['text']);
            ++$this->handoffs; // A controlled provider stub: no HTTP client or network is constructed.
        };
        self::assertSame(1, $this->runtime->runTenant($bundle['actor']->tenantId, 'source007-worker', $bundle['job']->jobKey));
        return $this->jobs->get($bundle['actor']->tenantId, $bundle['job']->jobKey);
    }

    private static function code(Throwable $error): string
    {
        return $error instanceof BusinessException ? $error->errorCode : $error->getMessage();
    }
    private function denied(callable $call, string $code): void
    {
        try {
            $call();
            self::fail('Expected denial ' . $code);
        } catch (BusinessException|DomainException $error) {
            self::assertSame($code, self::code($error));
        }
    }

    public function testNativeWorkerConsumesAuthoritativeSourceAndRechecksFinalFence(): void
    {
        $bundle = $this->issue();
        $before = $this->sessions->calls;
        $job = $this->runJob($bundle);
        self::assertNull($this->worker->error, $this->worker->error?->getMessage() ?? '');
        self::assertSame('succeeded', $job->status);
        self::assertSame(1, $this->handoffs);
        self::assertGreaterThan($before + 5, $this->sessions->calls);
        self::assertSame(1, Db::name('document_source_grant')->count());
        self::assertTrue($this->contexts->isEmpty());
        self::assertArrayNotHasKey('session_key', $bundle['receipt']);
    }

    public function testCallerCreatedExecutionWrongTaskWorkerPurposeAndExpiredRootAreDenied(): void
    {
        $bundle = $this->issue();
        $retained = null;
        $job = $this->runJob($bundle, function ($context, JobExecution $execution) use ($bundle, &$retained): void {
            $retained = $execution;
            $forged = new JobExecution($execution->jobKey, $execution->tenantId, $execution->attemptNumber, $execution->payload, static fn() => null, static fn() => null);
            $this->denied(fn() => $this->runtime->sourceActor($forged, self::binding($execution->jobKey)), 'TASK_SOURCE_EXECUTION_DENIED');
            foreach ([
                new SourceTaskBinding('job_' . str_repeat('a', 32), 'custom.ai-assistant', 'custom.ai-assistant.task', 'use', 'custom.ai-assistant.generate'),
                new SourceTaskBinding($execution->jobKey, 'custom.other', 'custom.ai-assistant.task', 'use', 'custom.ai-assistant.generate'),
                new SourceTaskBinding($execution->jobKey, 'custom.ai-assistant', 'custom.ai-assistant.task', 'manage', 'custom.ai-assistant.generate'),
                new SourceTaskBinding($execution->jobKey, 'custom.ai-assistant', 'custom.other.task', 'use', 'custom.ai-assistant.generate'),
                new SourceTaskBinding($execution->jobKey, 'custom.ai-assistant', 'custom.ai-assistant.task', 'use', 'custom.ai-assistant.verify-manual'),
            ] as $binding) {
                try {
                    $this->documents->redeemTaskSource($execution, $bundle['receipt']['grant_key'], $binding);
                    self::fail('wrong scope accepted');
                } catch (DomainException $error) {
                    self::assertContains($error->getMessage(), ['TASK_SOURCE_EXECUTION_DENIED','TASK_SOURCE_CONSUMER_DENIED']);
                }
            }
            $this->documents->redeemTaskSource($execution, $bundle['receipt']['grant_key'], self::binding($execution->jobKey));
        });
        self::assertNull($this->worker->error, $this->worker->error?->getMessage() ?? '');
        self::assertSame('succeeded', $job->status);
        $this->denied(fn() => $this->runtime->sourceActor($retained, self::binding($retained->jobKey)), 'TASK_SOURCE_EXECUTION_DENIED');
    }

    public function testCrossTenantGrantIsInvisibleUnderTheActualSecondTenantWorker(): void
    {
        $first = $this->issue(1);
        $second = $this->issue(2);
        $job = $this->runJob($second, fn($context, $execution) => $this->documents->redeemTaskSource($execution, $first['receipt']['grant_key'], self::binding($execution->jobKey)));
        self::assertSame('dead', $job->status);
        self::assertSame('DOCUMENT_SOURCE_GRANT_DENIED', self::code($this->worker->error));
        self::assertSame(0, $this->handoffs);
    }

    public function testRevocationAndExpiryPersistAndDenyConsumption(): void
    {
        $revoked = $this->issue();
        $this->admin(1, fn($actor) => $this->documents->revokeTaskSource($actor, $revoked['receipt']['grant_key']));
        $this->admin(1, fn($actor) => $this->documents->revokeTaskSource($actor, $revoked['receipt']['grant_key']));
        self::assertNotNull(Db::name('document_source_grant')->where('grant_key', $revoked['receipt']['grant_key'])->value('revoked_at'));
        self::assertSame('dead', $this->runJob($revoked)->status);
        self::assertSame('DOCUMENT_SOURCE_GRANT_REVOKED', self::code($this->worker->error));
        $expired = $this->issue();
        Db::name('document_source_grant')->where('grant_key', $expired['receipt']['grant_key'])->update(['expires_at' => time() - 1]);
        self::assertSame('dead', $this->runJob($expired)->status);
        self::assertSame('DOCUMENT_SOURCE_GRANT_EXPIRED', self::code($this->worker->error));
        self::assertSame(0, $this->handoffs);
    }

    public function testNativeMemberAndTaskScopeRevocationStopBeforeSourceHandler(): void
    {
        $bundle = $this->issue();
        $this->sessions->revoked[1] = true;
        self::assertSame('dead', $this->runJob($bundle)->status);
        self::assertSame(0, $this->worker->entered);
        $this->sessions->revoked[1] = false;
        $second = $this->issue();
        $this->worker->allowed = false;
        self::assertSame('dead', $this->runJob($second)->status);
        self::assertSame(0, $this->worker->entered);
        self::assertSame(0, $this->handoffs);
    }

    public function testCurrentOwnerPermissionAndMemberRevisionAreIndependentFences(): void
    {
        $bundle = $this->issue();
        $this->ownerAllowed = false;
        self::assertSame('dead', $this->runJob($bundle)->status);
        self::assertSame('ARTICLE_DOCUMENT_DENIED', self::code($this->worker->error));
        $this->ownerAllowed = true;
        $next = $this->issue();
        ++$this->sessions->revision;
        self::assertSame('dead', $this->runJob($next)->status);
        self::assertSame('DOCUMENT_SOURCE_GRANT_DENIED', self::code($this->worker->error));
        self::assertSame(0, $this->handoffs);
    }

    public function testOwnerRevisionDocumentDigestAndCachedAiTextChangesAreDenied(): void
    {
        $owner = $this->issue();
        Db::name('article')->where('id', 1)->update(['title' => 'Changed owner']);
        self::assertSame('dead', $this->runJob($owner)->status);
        self::assertSame('DOCUMENT_SOURCE_OWNER_CHANGED', self::code($this->worker->error));
        $version = $this->issue();
        $before = Db::name('document_revision')->where('id', 1)->value('snapshot_sha256');
        Db::name('document_revision')->where('id', 1)->update(['snapshot_sha256' => str_repeat('0', 64)]);
        self::assertSame('dead', $this->runJob($version)->status);
        self::assertSame('DOCUMENT_VERSION_DIGEST_INVALID', self::code($this->worker->error));
        Db::name('document_revision')->where('id', 1)->update(['snapshot_sha256' => $before]);
        $cached = $this->issue();
        $cached['source']['text'] = 'Unauthorized replacement';
        self::assertSame('dead', $this->runJob($cached)->status);
        self::assertSame('AI_SOURCE_FIXED_VERSION_CHANGED', self::code($this->worker->error));
        self::assertSame(0, $this->handoffs);
    }

    public function testRevocationAfterRedemptionIsRecheckedBeforeProvider(): void
    {
        $bundle = $this->issue();
        $job = $this->runJob($bundle, function ($context, JobExecution $execution) use ($bundle): void {
            $this->sources->authorizeExternalSend($execution, $bundle['receipt'], $bundle['source'], '/synthetic.invalid/not-used');
            // Represents another request committing revocation after the first check; no Admin root is inserted into this worker.
            Db::name('document_source_grant')->where('grant_key', $bundle['receipt']['grant_key'])->update(['revoked_at' => time()]);
            $execution->checkpoint();
            ++$this->handoffs;
        });
        self::assertSame('dead', $job->status);
        self::assertSame('DOCUMENT_SOURCE_GRANT_REVOKED', self::code($this->worker->error));
        self::assertSame(0, $this->handoffs);
    }

    public function testReplayConflictRollbackAndNativeRetryRecovery(): void
    {
        $bundle = $this->issue();
        $this->admin(1, function ($actor) use ($bundle): void {
            $version = $this->documents->versionSnapshot($actor, 1, 1);
            $again = Db::transaction(fn() => $this->documents->issueTaskSource($actor, $version, $bundle['owner'], self::binding($bundle['job']->jobKey), $bundle['expires'], $bundle['request']));
            self::assertSame($bundle['receipt'], $again);
            $this->denied(fn() => Db::transaction(fn() => $this->documents->issueTaskSource(
                $actor,
                $version,
                $bundle['owner'],
                self::binding($bundle['job']->jobKey),
                $bundle['expires'] + 1,
                $bundle['request'],
            )), 'DOCUMENT_SOURCE_REQUEST_CONFLICT');
        });
        self::assertSame(1, Db::name('document_source_grant')->count());
        $this->admin(1, function ($actor): void {
            $version = $this->documents->versionSnapshot($actor, 1, 1);
            try {
                Db::transaction(function () use ($actor, $version): void {
                    $job = $this->runtime->publisher($this->worker)->publish($this->worker->context($actor), $this->worker->taskType(), ['task_id' => 1], 'rollback-native-request');
                    $this->documents->issueTaskSource(
                        $actor,
                        $version,
                        new DocumentOwnerReference('peanut.article', 'article', '1', 'body'),
                        self::binding($job->jobKey),
                        time() + 3600,
                        'rollback-grant-request',
                    );
                    throw new RuntimeException('rollback-intent');
                });
                self::fail('expected rollback');
            } catch (RuntimeException $error) {
                self::assertSame('rollback-intent', $error->getMessage());
            }
        });
        self::assertSame(1, Db::name('document_source_grant')->count());
        self::assertSame(1, Db::name('task_job')->count());
        $failed = $this->runJob($bundle, function ($context, JobExecution $execution) use ($bundle): void {
            $this->documents->redeemTaskSource($execution, $bundle['receipt']['grant_key'], self::binding($execution->jobKey));
            throw new RuntimeException('CONTROLLED_BEFORE_PROVIDER_FAILURE');
        });
        self::assertSame('dead', $failed->status);
        $this->admin(1, function ($actor) use ($bundle, $failed): void {
            $context = AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($actor, TaskJobService::RESOURCE_KEY, 'manage', [], str_repeat('r', 64)));
            $this->runtime->jobs()->retry($context, $bundle['job']->jobKey, $failed->revision);
        });
        self::assertSame('succeeded', $this->runJob($bundle)->status);
        self::assertSame(1, $this->handoffs);
        self::assertSame(1, Db::name('document_source_grant')->count());
    }

    public function testBaseDocumentAndFileRemainAvailableWhenTaskIsDisabled(): void
    {
        $this->disabled['peanut.task'] = true;
        $this->admin(1, function ($actor): void {
            self::assertSame(1, $this->documents->versionSnapshot($actor, 1, 1)->documentId);
            $version = $this->documents->versionSnapshot($actor, 1, 1);
            $this->denied(fn() => Db::transaction(fn() => $this->documents->issueTaskSource(
                $actor,
                $version,
                new DocumentOwnerReference('peanut.article', 'article', '1', 'body'),
                self::binding('job_' . str_repeat('b', 32)),
                time() + 100,
                'missing-task-request',
            )), 'DOCUMENT_SOURCE_TASK_UNAVAILABLE');
            $path = $this->directory . '/source.txt';
            file_put_contents($path, 'Synthetic source bytes');
            $stored = $this->storage->storePath(1, $actor->memberId, 'document.resource', $path, 'source.txt', 'text/plain');
            self::assertStringStartsWith('file_', $stored['file_key']);
        });
    }

    public function testRichTextFileReferencesStayExplicitlyUnsupported(): void
    {
        $this->seedDocument(3, 1, reference: true);
        $this->denied(fn() => $this->issue(1, 3), 'DOCUMENT_SOURCE_REFERENCES_UNSUPPORTED');
        self::assertSame(0, Db::name('document_source_grant')->count());
        self::assertSame(0, Db::name('task_job')->count());
    }


    public function testNativeFileSnapshotChecksCurrentPermissionClaimMimeAndActualBytes(): void
    {
        $capture = $this->admin(1, function ($actor): array {
            $path = $this->directory . '/file-source.txt';
            file_put_contents($path, 'Synthetic immutable source 007');
            $stored = $this->storage->storePath(1, $actor->memberId, 'document.resource', $path, 'source.txt', 'text/plain');
            $meta = $this->storage->describeForDocument($actor, $stored['file_key']);
            return $this->storage->captureForDocument($actor, $stored['file_key'], $meta['sha256'], $meta['revision'], 'source-capture-007');
        });
        $this->seedDocument(3, 1, ['file_key' => $capture['file_key'],'sha256' => $capture['sha256'],'mime' => $capture['verified_mime_type']]);
        // The pre-existing Document/File claim operation is fixture setup, not a claimed integration result.
        Db::name('file_immutable_snapshot')->where('file_key', $capture['file_key'])->update(['status' => 'claimed','claimed_document_id' => 3]);
        $opened = $this->admin(1, fn() => $this->storage->openForTenant(1, $capture['file_key']));
        $currentReceipt = null;
        $consume = function ($context, JobExecution $execution) use ($capture, &$currentReceipt): void {
            $grant = $currentReceipt;
            $version = $this->documents->redeemTaskSource($execution, $grant['grant_key'], self::binding($execution->jobKey));
            self::assertSame($capture['file_key'], $version->fileKey);
            self::assertInstanceOf(SystemExecutionContext::class, $this->current->current());
            $execution->checkpoint();
            ++$this->handoffs;
        };
        $ok = $this->issue(1, 3);
        $currentReceipt = $ok['receipt'];
        self::assertSame('succeeded', $this->runJob($ok, $consume)->status, $this->worker->error?->getMessage() ?? '');
        self::assertSame(1, $this->handoffs);
        $permission = $this->issue(1, 3);
        $currentReceipt = $permission['receipt'];
        $this->fileAllowed = false;
        self::assertSame('dead', $this->runJob($permission, $consume)->status);
        self::assertSame('FILE_ACCESS_DENIED', self::code($this->worker->error));
        $this->fileAllowed = true;
        foreach (['claim','mime','bytes'] as $change) {
            $bundle = $this->issue(1, 3);
            $currentReceipt = $bundle['receipt'];
            if ($change === 'claim') {
                Db::name('file_immutable_snapshot')->where('file_key', $capture['file_key'])->update(['claimed_document_id' => 999]);
            }
            if ($change === 'mime') {
                Db::name('file_object')->where('file_key', $capture['file_key'])->update(['media_type' => 'image/png']);
            }
            if ($change === 'bytes') {
                file_put_contents($opened['path'], 'Changed immutable bytes');
            }
            self::assertSame('dead', $this->runJob($bundle, $consume)->status);
            self::assertSame($change === 'bytes' ? 'FILE_BYTES_CHANGED' : 'FILE_DOCUMENT_SNAPSHOT_CHANGED', self::code($this->worker->error));
            Db::name('file_immutable_snapshot')->where('file_key', $capture['file_key'])->update(['claimed_document_id' => 3]);
            Db::name('file_object')->where('file_key', $capture['file_key'])->update(['media_type' => $capture['verified_mime_type']]);
        }
        self::assertSame(1, $this->handoffs);
    }


    public function testLeaseLossAndForgedNativeEnvelopeNeverReachTheProvider(): void
    {
        $bundle = $this->issue();
        $job = $this->runJob($bundle, function ($context, JobExecution $execution) use ($bundle): void {
            $this->documents->redeemTaskSource($execution, $bundle['receipt']['grant_key'], self::binding($execution->jobKey));
            Db::name('task_job')->where('job_key', $execution->jobKey)->update(['lease_token_hash' => str_repeat('0', 64)]);
            $this->runtime->sourceActor($execution, self::binding($execution->jobKey));
            ++$this->handoffs;
        });
        self::assertNotSame('succeeded', $job->status);
        self::assertInstanceOf(PeanutAdmin\Modules\Task\Job\Application\TaskJobException::class, $this->worker->error);
        self::assertSame('TASK_STATE_CONFLICT', $this->worker->error->problemCode);
        $forged = $this->issue();
        $envelope = Db::name('task_job')->where('job_key', $forged['job']->jobKey)->value('trusted_envelope');
        Db::name('task_job')->where('job_key', $forged['job']->jobKey)->update(['trusted_envelope' => $envelope . 'x']);
        self::assertSame('dead', $this->runJob($forged)->status);
        self::assertSame(0, $this->worker->entered);
        self::assertSame(0, $this->handoffs);
    }

    public function testIssuanceNeedsAnActualNativeJobAndCurrentOwnerPermission(): void
    {
        $this->admin(1, function ($actor): void {
            $version = $this->documents->versionSnapshot($actor, 1, 1);
            try {
                Db::transaction(fn() => $this->documents->issueTaskSource(
                    $actor,
                    $version,
                    new DocumentOwnerReference('peanut.article', 'article', '1', 'body'),
                    self::binding('job_' . str_repeat('c', 32)),
                    time() + 3600,
                    'unknown-native-job',
                ));
                self::fail('Arbitrary native job ID granted source authority');
            } catch (PeanutAdmin\Modules\Task\Job\Application\TaskJobException $error) {
                self::assertNotSame('', $error->problemCode);
            }
        });
        self::assertSame(0, Db::name('document_source_grant')->count());
        $this->ownerAllowed = false;
        $this->denied(fn() => $this->issue(), 'ARTICLE_DOCUMENT_DENIED');
        self::assertSame(0, Db::name('document_source_grant')->count());
    }


    private function bootstrapAi(): void
    {
        $server = dirname(__DIR__, 4);
        SourceGrantFixture\aiSchema($server . '/app/modules/custom/ai_assistant/database/migrations/20261101150001_create_ai_assistant_business.php');
        $this->aiEngine = new SourceGrantFixture\DataEngine();
        $constraints = new PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier();
        $access = new Custom\Modules\AiAssistant\Service\AiAccess($this->permissionQuery, $this->aiEngine, $constraints, $this->current, new SourceGrantFixture\Audit());
        $secrets = $this->createStub(PeanutAdmin\Settings\Secret\SecretProtector::class);
        $secrets->method('reveal')->willReturnCallback(function (): string {
            ++$this->reveals;
            if ($this->onReveal !== null) {
                ($this->onReveal)();
            }
            return 'synthetic-not-real-api-key';
        });
        $configuration = new Custom\Modules\AiAssistant\Service\AiConfiguration($access, $secrets);
        $quota = new Custom\Modules\AiAssistant\Service\AiQuota($access);
        $private = new Custom\Modules\AiAssistant\Service\AiPrivateStore();
        if (!is_dir($this->app->getRuntimePath())) {
            mkdir($this->app->getRuntimePath(), 0700, true);
        }
        $transport = $this->createStub(app\common\contract\http\OutboundHttpTransport::class);
        $transport->method('send')->willReturnCallback(function ($request): app\common\value\http\OutboundHttpResponse {
            ++$this->providerCalls;
            self::assertStringStartsWith('https://source007.invalid/', $request->url);
            self::assertStringContainsString('Synthetic tenant 1 fixed source', $request->body);
            self::assertInstanceOf(SystemExecutionContext::class, $this->current->current());
            $body = json_encode(['id' => 'synthetic-result','choices' => [['message' => ['role' => 'assistant','content' => 'Controlled provider candidate']]],
                'usage' => ['prompt_tokens' => 10,'completion_tokens' => 3,'total_tokens' => 13]], JSON_THROW_ON_ERROR);
            $sink = fopen($request->sink, 'w+b');
            self::assertIsResource($sink);
            self::assertSame(strlen($body), fwrite($sink, $body));
            fclose($sink);
            return new app\common\value\http\OutboundHttpResponse(200, '', ['content-type' => 'application/json','x-request-id' => 'synthetic-provider-request']);
        });
        $execution = new Custom\Modules\AiAssistant\Service\AiExecution(
            $access,
            $this->sources,
            $configuration,
            $private,
            $quota,
            new Custom\Modules\AiAssistant\Service\AiProtocol(),
            new Custom\Modules\AiAssistant\Service\AiOutputVerifier(),
            $transport,
            $this->current,
        );
        $authorization = new Custom\Modules\AiAssistant\Service\AiTaskAuthorization($access, $this->sessions, $this->aiEngine, $constraints);
        $native = new Custom\Modules\AiAssistant\Service\AiNativeTask($authorization, $execution);
        $this->app->instance($execution::class, $execution);
        $this->app->instance($authorization::class, $authorization);
        $this->app->instance($native::class, $native);
        $this->observedAi = new SourceGrantFixture\ObservedDefinition($native);
        $directory = new SourceGrantFixture\Directory();
        $unused = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->runtime = new ThinkPhpTaskJobRuntime(
            $this->jobs,
            str_repeat('j', 32),
            $this->contexts,
            $this->current,
            $directory,
            new app\common\infrastructure\module\ModuleExecutionBoundary($this->current, $this->moduleRepository, $directory),
            new ModuleGuard($this->moduleRepository),
            $unused(app\common\services\CrontabCommandService::class),
            static fn() => null,
            [$this->observedAi],
            1,
            $this->createStub(PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog::class),
            new SourceGrantFixture\Entitlements(),
        );
        $this->app->instance(TaskSourceAuthorizations::class, $this->runtime);
        $this->aiTasks = new Custom\Modules\AiAssistant\Service\AiTasks($access, $configuration, $this->sources, $quota, $private, $this->runtime, $authorization);
        $this->aiRequestStatus = new Custom\Modules\AiAssistant\Service\AiRequestStatus($access, $this->sources);
        foreach ([$this->aiTasks::class => 'AiTasks.php', $execution::class => 'AiExecution.php', $authorization::class => 'AiTaskAuthorization.php'] as $class => $file) {
            self::assertSame(realpath($server . '/app/modules/custom/ai_assistant/src/Service/' . $file), (new ReflectionClass($class))->getFileName());
        }
        $configs = [
            1 => ['connection', ['protocol' => 'openai_text','origin' => 'https://source007.invalid','api_prefix' => '/v1','responsibility' => 'self_deployed','daily_request_limit' => 10,'credential_configured' => true]],
            2 => ['model', ['connection_id' => 1,'capability' => 'text','provider_model_id' => 'synthetic-text','input_limit_chars' => 32000,'max_output_tokens' => 100]],
            3 => ['template', ['capability' => 'text','instructions' => 'Controlled synthetic task']],
            4 => ['route', ['capability' => 'text','consumer_module' => 'general','model_id' => 2,'template_id' => 3]],
        ];
        foreach ($configs as $id => [$kind, $details]) {
            Db::name('ai_assistant_config')->insert(['id' => $id,'tenant_id' => 1,'title' => 'Synthetic ' . $kind,'status' => 'enabled','version' => 1,
                'created_by' => 11,'details' => json_encode($details, JSON_THROW_ON_ERROR),'request_id' => 'fixture-config-' . $id,
                'created_at' => time(),'updated_at' => time(),'kind' => $kind,'deleted_at' => 0,
                'secret_payload' => $kind === 'connection' ? json_encode(['ciphertext' => 'synthetic-ciphertext','nonce' => 'synthetic-nonce','key_id' => 'synthetic-key']) : null]);
        }
    }

    private static function aiInput(int $document = 1): array
    {
        return ['route_id' => 4,'document_id' => $document,'revision_id' => $document,'instruction' => 'Produce a synthetic candidate',
            'consumer_module' => 'general','confirm_possible_duplicate_cost' => false];
    }

    private function aiCreate(string $request, int $document = 1): array
    {
        return $this->admin(1, fn($actor) => $this->aiTasks->create($actor, 'Synthetic AI source task', self::aiInput($document), $request));
    }

    private function aiRun(array $task): PeanutAdmin\Modules\Task\Contract\JobRecord
    {
        $this->observedAi->error = null;
        $this->observedAi->entered = 0;
        self::assertSame(1, $this->runtime->runTenant(1, 'source007-real-ai-worker', $task['details']['native_job_key']));
        return $this->jobs->get(1, $task['details']['native_job_key']);
    }

    private function aiCommand(array $payload): array
    {
        $input = Custom\Modules\AiAssistant\Validation\AiInput::taskAction($payload);
        return $this->admin(1, fn($actor) => $this->aiTasks->command($actor, $input['id'], $input['action'], $input['job_revision'], $input['request_id'], $input['version']));
    }

    private function grantInsertFailure(bool $enabled): void
    {
        Db::execute($enabled
            ? "CREATE TRIGGER source007_fail_grant BEFORE INSERT ON pa_document_source_grant BEGIN SELECT RAISE(ABORT, 'synthetic grant insert failure'); END"
            : 'DROP TRIGGER source007_fail_grant');
    }

    public function testRealAiTasksRejectUnsupportedBeforePersistenceOrQuota(): void
    {
        $this->bootstrapAi();
        $this->seedDocument(3, 1, reference: true);
        $this->denied(fn() => $this->aiCreate('unsupported-ai-source', 3), 'DOCUMENT_SOURCE_REFERENCES_UNSUPPORTED');
        foreach (['ai_assistant_task','ai_assistant_quota','task_job','document_source_grant'] as $table) {
            self::assertSame(0, Db::name($table)->count());
        }
        self::assertSame(0, $this->providerCalls);
    }

    public function testRealAiTasksAtomicGrantFailureRecoversSameRequestWithoutDoubleReservation(): void
    {
        $this->bootstrapAi();
        $this->grantInsertFailure(true);
        try {
            $this->aiCreate('atomic-ai-source');
            self::fail('Expected grant persistence failure');
        } catch (think\db\exception\PDOException $error) {
            self::assertStringContainsString('synthetic grant insert failure', $error->getMessage());
        }
        $preparing = Db::name('ai_assistant_task')->where('tenant_id', 1)->find();
        self::assertSame('preparing', $preparing['status']);
        self::assertNull($preparing['job_key']);
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        self::assertSame(0, Db::name('task_job')->count());
        self::assertSame(0, Db::name('document_source_grant')->count());
        $this->grantInsertFailure(false);
        $task = $this->aiCreate('atomic-ai-source');
        self::assertSame((int) $preparing['id'], $task['id']);
        self::assertSame('queued', $task['status']);
        self::assertSame($task, $this->aiCreate('atomic-ai-source'));
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        self::assertSame(1, Db::name('task_job')->count());
        self::assertSame(1, Db::name('document_source_grant')->count());
        $this->admin(1, function ($actor): void {
            $input = self::aiInput();
            $input['instruction'] = 'Changed request';
            $this->denied(fn() => $this->aiTasks->create($actor, 'Synthetic AI source task', $input, 'atomic-ai-source'), 'AI_REQUEST_CONFLICT');
        });
        $job = $this->aiRun($task);
        self::assertNull($this->observedAi->error, $this->observedAi->error?->getMessage() ?? '');
        self::assertSame('succeeded', $job->status);
        self::assertSame(1, $this->providerCalls);
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('confirmed_requests'));
        self::assertSame(0, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        self::assertSame('awaiting_collection', Db::name('ai_assistant_task')->value('status'));
    }

    public function testRealAiPreparationCancelUsesBusinessCasPermissionAndOriginalInput(): void
    {
        $this->bootstrapAi();
        $this->grantInsertFailure(true);
        try {
            $this->aiCreate('cancel-ai-preparation');
            self::fail('Expected grant failure');
        } catch (think\db\exception\PDOException) {
        }
        $row = Db::name('ai_assistant_task')->where('tenant_id', 1)->find();
        $payload = ['id' => (int) $row['id'],'action' => 'cancel','version' => (int) $row['version'],'request_id' => 'cancel-preparation-request'];
        $this->manageAllowed = false;
        $this->denied(fn() => $this->aiCommand($payload), 'AI_NATIVE_TASK_PERMISSION_DENIED');
        $this->manageAllowed = true;
        $stale = $payload;
        --$stale['version'];
        $this->denied(fn() => $this->aiCommand($stale), 'AI_VERSION_CONFLICT');
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        $cancelled = $this->aiCommand($payload);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('not_sent', $cancelled['details']['usage']['state']);
        self::assertSame('not_sent', $cancelled['details']['quota']['state']);
        self::assertSame($cancelled, $this->aiCommand($payload));
        $descriptor = ['endpoint' => 'taskAction','kind' => 'task','id' => $payload['id'],'action' => 'cancel','request_id' => $payload['request_id']];
        $receipt = $this->admin(1, fn($actor) => $this->aiRequestStatus->status($actor, $descriptor));
        self::assertSame('applied', $receipt['state']);
        self::assertSame($cancelled, $receipt['result']);
        $this->manageAllowed = false;
        $this->denied(fn() => $this->admin(1, fn($actor) => $this->aiRequestStatus->status($actor, $descriptor)), 'AI_NATIVE_TASK_PERMISSION_DENIED');
        $this->manageAllowed = true;
        $wrong = $payload;
        ++$wrong['version'];
        $this->denied(fn() => $this->aiCommand($wrong), 'AI_REQUEST_CONFLICT');
        self::assertSame(0, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        self::assertSame(0, Db::name('task_job')->count());
        self::assertSame(0, $this->providerCalls);
        $invalid = $payload;
        $invalid['job_revision'] = 1;
        $this->denied(fn() => Custom\Modules\AiAssistant\Validation\AiInput::taskAction($invalid), 'AI_INPUT_INVALID');
        $invalid = $payload;
        $invalid['action'] = 'retry';
        $this->denied(fn() => Custom\Modules\AiAssistant\Validation\AiInput::taskAction($invalid), 'AI_INPUT_INVALID');
        $this->grantInsertFailure(false);
        self::assertSame('cancelled', $this->aiCreate('cancel-ai-preparation')['status']);
    }

    public function testRealAiExecutionRevokedSourceReleasesUnsentQuotaAndRetryIsExplicit(): void
    {
        $this->bootstrapAi();
        $task = $this->aiCreate('revoked-ai-source');
        $this->admin(1, fn($actor) => $this->documents->revokeTaskSource($actor, $task['details']['source_authorization']['grant_key']));
        $job = $this->aiRun($task);
        self::assertSame('dead', $job->status);
        self::assertSame('DOCUMENT_SOURCE_GRANT_REVOKED', self::code($this->observedAi->error));
        self::assertSame(0, $this->providerCalls);
        self::assertSame(0, $this->reveals);
        $row = Db::name('ai_assistant_task')->where('tenant_id', 1)->find();
        $data = json_decode($row['details'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('blocked_source_authorization', $row['status']);
        self::assertSame('not_sent', $data['quota']['state']);
        self::assertSame('not_sent', $data['usage']['state']);
        self::assertNull($data['usage']['billing_amount']);
        self::assertArrayNotHasKey('attempt_started_at', $data);
        self::assertSame(0, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        $retry = $this->aiCommand(['id' => $task['id'],'action' => 'retry','job_revision' => $job->revision,'request_id' => 'retry-revoked-ai-source']);
        self::assertNotSame($task['details']['source_authorization']['grant_key'], $retry['details']['source_authorization']['grant_key']);
        self::assertSame('prepared', $retry['details']['stage']);
        self::assertNull($retry['details']['execution_block']);
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        self::assertSame('succeeded', $this->aiRun($retry)->status, $this->observedAi->error?->getMessage() ?? '');
        self::assertSame(1, $this->providerCalls);
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('confirmed_requests'));
    }

    public function testRealAiFinalSourceFenceRejectsBeforeProviderAndDeadUnsentTaskCanCancel(): void
    {
        $this->bootstrapAi();
        $task = $this->aiCreate('final-source-fence-ai');
        $this->onReveal = static function () use ($task): void {
            // Another request revokes after redemption and before the actual provider call.
            Db::name('document_source_grant')->where('grant_key', $task['details']['source_authorization']['grant_key'])->update(['revoked_at' => time()]);
        };
        $job = $this->aiRun($task);
        self::assertSame('dead', $job->status);
        self::assertSame(0, $this->providerCalls);
        self::assertSame(1, $this->reveals);
        $row = Db::name('ai_assistant_task')->where('tenant_id', 1)->find();
        $data = json_decode($row['details'], true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('prepared', $data['stage']);
        self::assertSame('not_sent', $data['usage']['state']);
        self::assertArrayNotHasKey('attempt_started_at', $data);
        $cancel = $this->aiCommand(['id' => $task['id'],'action' => 'cancel','job_revision' => $job->revision,'request_id' => 'cancel-dead-unsent-ai']);
        self::assertSame('cancelled', $cancel['status']);
        self::assertSame('not_sent', $cancel['details']['quota']['state']);
        self::assertSame(0, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        self::assertSame(0, (int) Db::name('ai_assistant_quota')->value('confirmed_requests'));
    }

    private function nativeDenied(callable $work, string $expected): void
    {
        try {
            $work();
            self::fail('Expected native task denial: ' . $expected);
        } catch (PeanutAdmin\Modules\Task\Job\Application\TaskJobException $error) {
            self::assertSame($expected, $error->problemCode);
        }
    }

    private function deadUnsentAiTask(string $request): array
    {
        $this->bootstrapAi();
        $task = $this->aiCreate($request);
        $this->onReveal = static function () use ($task): void {
            Db::name('document_source_grant')->where('grant_key', $task['details']['source_authorization']['grant_key'])->update(['revoked_at' => time()]);
        };
        $job = $this->aiRun($task);
        self::assertSame('dead', $job->status);
        self::assertSame(0, $this->providerCalls);
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        return [$task, $job];
    }

    private function aiCancellationFacts(int $taskId): array
    {
        return [
            'task' => Db::name('ai_assistant_task')->where('id', $taskId)->find(),
            'quota' => Db::name('ai_assistant_quota')->select()->toArray(),
            'events' => Db::name('ai_assistant_event')->select()->toArray(),
            'grants' => Db::name('document_source_grant')->select()->toArray(),
        ];
    }

    public function testRealAiDeadCancellationRequiresCompleteLockedNativeTerminalFacts(): void
    {
        [$task, $job] = $this->deadUnsentAiTask('terminal-facts-ai');
        $before = $this->aiCancellationFacts($task['id']);
        $native = Db::name('task_job')->where('job_key', $job->jobKey)->find();
        $attempt = Db::name('task_job_attempt')->where('job_id', $native['id'])->find();
        self::assertNotNull($native['completed_at']);
        self::assertNotNull($attempt);
        foreach ([
            'incomplete' => ['completed_at' => null],
            'lease-token' => ['lease_token_hash' => str_repeat('a', 64)],
            'lease-owner' => ['lease_owner_hash' => str_repeat('b', 64)],
            'lease-expiry' => ['lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 60) . '.000'],
            'running-attempt' => [],
            'cancelled' => ['status' => 'cancelled'],
        ] as $case => $changes) {
            if ($changes !== []) {
                Db::name('task_job')->where('id', $native['id'])->update($changes);
            }
            if ($case === 'running-attempt') {
                Db::name('task_job_attempt')->where('id', $attempt['id'])->update(['status' => 'running']);
            }
            $this->nativeDenied(fn() => $this->aiCommand(['id' => $task['id'],'action' => 'cancel',
                'job_revision' => $job->revision,'request_id' => 'reject-terminal-' . $case]), 'TASK_STATE_CONFLICT');
            self::assertSame($before, $this->aiCancellationFacts($task['id']), $case . ' must not alter business/quota/grant/event facts');
            self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('reserved_requests'), $case);
            self::assertSame(0, $this->providerCalls, $case);
            if ($changes !== []) {
                Db::name('task_job')->where('id', $native['id'])->update(array_intersect_key($native, $changes));
            }
            if ($case === 'running-attempt') {
                Db::name('task_job_attempt')->where('id', $attempt['id'])->update(['status' => $attempt['status']]);
            }
        }
    }

    public function testRealAiDeadCancellationRejectsStaleNativeRevisionWithoutReleasingQuota(): void
    {
        [$task, $job] = $this->deadUnsentAiTask('stale-terminal-ai');
        $before = $this->aiCancellationFacts($task['id']);
        self::assertGreaterThan(1, $job->revision);
        $this->nativeDenied(fn() => $this->aiCommand(['id' => $task['id'],'action' => 'cancel',
            'job_revision' => $job->revision - 1,'request_id' => 'reject-stale-terminal']), 'TASK_STATE_CONFLICT');
        self::assertSame($before, $this->aiCancellationFacts($task['id']));
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        self::assertSame(0, $this->providerCalls);
    }

    public function testRealAiDeadCancellationPropagatesManagementAndNativePermissionDenial(): void
    {
        [$task, $job] = $this->deadUnsentAiTask('denied-terminal-ai');
        $before = $this->aiCancellationFacts($task['id']);
        $payload = ['id' => $task['id'],'action' => 'cancel','job_revision' => $job->revision,'request_id' => 'reject-denied-terminal'];
        $this->manageAllowed = false;
        $this->denied(fn() => $this->aiCommand($payload), 'AI_NATIVE_TASK_PERMISSION_DENIED');
        self::assertSame($before, $this->aiCancellationFacts($task['id']));
        $this->manageAllowed = true;
        // The real native cancel contract denies non-member jobs before any state-conflict recovery.
        Db::name('task_job')->where('job_key', $job->jobKey)->update(['authorization_profile' => 'negative_fact']);
        $this->nativeDenied(fn() => $this->aiCommand($payload), 'TASK_PERMISSION_DENIED');
        self::assertSame($before, $this->aiCancellationFacts($task['id']));
        self::assertSame(1, (int) Db::name('ai_assistant_quota')->value('reserved_requests'));
        self::assertSame(0, $this->providerCalls);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }
}
