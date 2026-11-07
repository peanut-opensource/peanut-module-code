<?php

declare(strict_types=1);

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentRevisionComments;
use PeanutAdmin\Modules\Document\Controller\DocumentRevisionCommentController;
use PeanutAdmin\Modules\Document\Model\DocumentCommentMessage;
use PeanutAdmin\Modules\Document\Model\DocumentCommentOperation;
use PeanutAdmin\Modules\Document\Service\DocumentAuthorizationService;
use PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry;
use PeanutAdmin\Modules\Document\Service\DocumentRevisionCommentService;
use PeanutAdmin\Modules\Document\Service\DocumentService;
use PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots;
use PeanutAdmin\Modules\File\Contract\FileReferenceCommands;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DocumentRevisionCommentTest extends TestCase
{
    private think\App $app;
    private DocumentCommentFixture\Sessions $sessions;
    private DocumentCommentFixture\Owner $owner;
    private DocumentCommentFixture\ReadFence $fence;
    private DocumentAuthorizationService $authorization;
    private DocumentCommands $commands;
    private DocumentQueries $queries;
    private DocumentRevisionComments $comments;
    private string $directory;
    private int $sequence = 0;
    private array $disabled = [];

    protected function setUp(): void
    {
        $server = dirname(__DIR__, 4);
        require_once $server . '/vendor/autoload.php';
        require_once $server . '/vendor/topthink/framework/src/helper.php';
        require_once __DIR__ . '/DocumentRevisionCommentSupport.php';
        // Only explicit external Identity and audit boundaries are synthetic.
        // Production Document/RichText classes use the unchanged normal Composer loader.
        class_alias(DocumentCommentFixture\ReadFence::class, PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationReadFence::class);
        class_alias(DocumentCommentFixture\Audit::class, app\common\services\audit\AuditContractHost::class);
        $temporaryRoot = getenv('PEANUT_DOCUMENT_COMMENT_TEST_TMP');
        $this->directory = ($temporaryRoot === false || $temporaryRoot === ''
            ? dirname($server) . '/.local/007-document-comments-20261107/tmp'
            : $temporaryRoot) . '/' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->app = new think\App($this->directory . '/');
        $this->app->instance(think\App::class, $this->app);
        $db = new think\DbManager();
        $db->setConfig(['default' => 'comments06', 'connections' => ['comments06' => [
            'type' => DocumentCommentFixture\Sqlite::class, 'database' => ':memory:', 'prefix' => 'pa_',
            'builder' => think\db\builder\Sqlite::class, 'fields_cache' => false,
        ]]]);
        $this->app->instance(think\DbManager::class, $db);
        $db->query('SELECT 1');
        $pdo = $db->connect()->getPdo();
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'comments06', 0);
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT,TABLE_NAME TEXT,COLUMN_NAME TEXT)');
        DocumentCommentFixture\schema(
            $server . '/app/modules/peanut/document/database/migrations/20261102000007_initial_document_schema.php',
            ['document','document_state','document_revision','document_file_ref','document_resource_binding','document_operation'],
        );
        DocumentCommentFixture\schema(
            $server . '/app/modules/peanut/document/database/migrations/20261107100007_document_revision_comments.php',
            ['document_comment_thread','document_comment_message','document_comment_operation'],
        );
        $contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($contexts);
        $this->app->instance(CurrentExecutionContext::class, $current);
        $this->app->instance(ExecutionContextStore::class, $contexts);
        $scope = new app\common\tenancy\MultiTenantDataScopePolicy($current);
        think\Model::maker(static function (think\Model $model) use ($db, $scope): void {
            $model->setOption('db', $db);
            if ($model instanceof app\common\model\TenantOwnedModel) {
                $model->setDataScopePolicy($scope);
            }
        });
        $this->sessions = new DocumentCommentFixture\Sessions();
        $this->owner = new DocumentCommentFixture\Owner($contexts);
        $this->app->instance(DocumentCommentFixture\Owner::class, $this->owner);
        $owners = new DocumentOwnerRegistry($this->app, ['example.notes:note' => DocumentCommentFixture\Owner::class]);
        $permissions = $this->createStub(AdminAuthorizationQuery::class);
        $permissions->method('principal')->willReturnCallback(static fn(TenantContext $actor) => AdminPrincipal::fromArray([
            'id' => $actor->memberId,'tenant_id' => $actor->tenantId,'account_id' => $actor->accountId,
            'authorization_revision' => $actor->authorizationRevision,
        ]));
        $modules = $this->createStub(ModuleRuntimeRepository::class);
        $modules->method('installation')->willReturnCallback(static fn(string $key) => new ModuleInstallationRecord($key, '1.0.0', 'active', 1, str_repeat('a', 64)));
        $modules->method('tenantModule')->willReturnCallback(fn(int $tenant, string $key) => new TenantModuleRecord(
            $tenant,
            $key,
            $key === 'peanut.collaboration' || isset($this->disabled[$key]) ? 'disabled' : 'enabled',
            null,
            null,
            1,
        ));
        $this->fence = new DocumentCommentFixture\ReadFence($contexts);
        $this->authorization = new DocumentAuthorizationService($this->sessions, $permissions, $contexts, $modules, $owners, $this->fence);
        $references = $this->createStub(FileReferenceCommands::class);
        $references->method('replaceDocumentReferences')->willReturnCallback(static function (TenantContext $actor, int $id, array $keys): void {
            self::assertSame([], $keys, 'This fixture does not synthesize File permission or file references');
        });
        $references->method('assertDocumentReferencesReadable')->willReturnCallback(static function (TenantContext $actor, int $id, array $keys): void {
            self::assertSame([], $keys, 'Actual public source verification reaches the File boundary with no resources');
        });
        $unused = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $documents = new DocumentService(
            $this->authorization,
            $references,
            $this->createStub(FileDocumentSnapshots::class),
            $this->createStub(FileStorage::class),
            new DocumentCommentFixture\Audit(),
            $unused(PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService::class),
            $contexts,
            $unused(PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry::class),
            $owners,
            $this->fence,
            $modules,
        );
        $this->commands = $documents;
        $this->queries = $documents;
        $this->comments = new DocumentRevisionCommentService($this->authorization, $documents, $owners, $this->fence);
        $this->app->instance(DocumentRevisionComments::class, $this->comments);
        $this->app->instance(DocumentRevisionCommentService::class, $this->comments);
        self::assertSame(DocumentRevisionCommentService::class, (new PeanutAdmin\Modules\Document\ModuleProvider())->bindings()[DocumentRevisionComments::class]);
        foreach ([DocumentService::class => ['peanut/document','peanut-document','Service/DocumentService.php'],
            DocumentRevisionCommentService::class => ['peanut/document','peanut-document','Service/DocumentRevisionCommentService.php'],
            RichTextProjection::class => ['peanut/rich_text','peanut-rich-text','Contract/RichTextProjection.php']] as $class => [$module, $package, $file]) {
            $loaded = (new ReflectionClass($class))->getFileName();
            $installed = realpath($server . '/vendor/peanut-business/' . $package . '/src/' . $file);
            self::assertSame($installed, realpath($loaded), 'Use the installed Composer class, without test production namespace overrides');
            self::assertSame(hash_file('sha256', $server . '/app/modules/' . $module . '/src/' . $file), hash_file('sha256', $loaded));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
    }

    private static function projection(string $text = "中文😀e\u{0301}"): array
    {
        return ['schemaVersion' => RichTextProjection::SCHEMA,'editorModel' => 'tiptap-prosemirror',
            'content' => ['type' => 'doc','content' => [
                ['type' => 'paragraph','content' => [['type' => 'text','text' => $text]]],
                ['type' => 'paragraph','content' => [['type' => 'text','text' => 'end']]],
            ]], 'annotations' => []];
    }

    /** Every source is created, saved, frozen and read through actual public Document contracts. */
    private function revision(int $member = 11): array
    {
        $actor = $this->sessions->actor($member);
        $owner = $this->owner->add($actor->tenantId, 'note-' . ++$this->sequence);
        return $this->authorization->run($actor, function (TenantContext $current) use ($owner): array {
            $key = 'source-' . $this->sequence;
            $document = $this->commands->create($current, $owner, 'rich_text', $key . '-create');
            $saved = $this->commands->saveSolo($current, $document['document_id'], 0, self::projection(), $key . '-save');
            $revision = $this->commands->freeze($current, $document['document_id'], $saved['state_seq'], 'Synthetic fixed revision', $key . '-freeze');
            $read = $this->queries->versionSnapshot($current, $document['document_id'], $revision['revision_id']);
            self::assertSame($revision['digest'], $read->digest);
            self::assertSame(1, $read->sourceStateSeq);
            self::assertSame(RichTextProjection::canonical(self::projection()), $read->structuredContent);
            return $revision;
        });
    }
    private function source(array $revision, int $member = 11): array
    {
        return $this->comments->threads($this->sessions->actor($member), $revision['document_id'], $revision['revision_id'])['source'];
    }
    private function input(array $source, string $request = 'comment-create'): array
    {
        return ['request_id' => $request,'version_digest' => $source['version_digest'],'text_profile' => $source['text_profile'],
            'text_sha256' => $source['text_sha256'],'start_offset' => 0,'end_offset' => 2,'quote' => '中文','body' => 'Synthetic first comment'];
    }
    private function transition(array $created, string $request): array
    {
        return ['request_id' => $request,'version_digest' => $created['version_digest'],'text_profile' => $created['text_profile'],
            'text_sha256' => $created['text_sha256'],'thread_id' => $created['thread_id'],'expected_thread_revision' => $created['thread_revision']];
    }
    private function state(): array
    {
        $state = [];
        foreach (['document','document_state','document_revision','document_file_ref','document_resource_binding','document_operation',
            'document_comment_thread','document_comment_message','document_comment_operation'] as $table) {
            $state[$table] = Db::query('SELECT * FROM pa_' . $table . ' ORDER BY 1');
        }
        return $state;
    }
    private function rejected(string $code, callable $operation): void
    {
        $before = $this->state();
        try {
            $operation();
            self::fail('Expected rejection: ' . $code);
        } catch (BusinessException $error) {
            self::assertSame($code, $error->errorCode);
        }
        self::assertSame($before, $this->state(), 'All Document/source/comment/request rows must remain unchanged');
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
    }


    #[DataProvider('readEndpoints')]
    public function testLockedOwnerKeepsFixedRevisionReadable(string $endpoint): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $created = $this->comments->createThread(
            $this->sessions->actor(11),
            $revision['document_id'],
            $revision['revision_id'],
            $this->input($source, 'state-lock-readable'),
        );
        $reader = $this->sessions->actor(22);
        $read = fn(): array => $endpoint === 'threads'
            ? $this->comments->threads($reader, $revision['document_id'], $revision['revision_id'])
            : $this->comments->thread($reader, $revision['document_id'], $revision['revision_id'], $created['thread_id']);
        $before = $this->state();
        $this->owner->authorizationFailures['edit'] = BusinessException::conflict(
            'COMMENT_FIXTURE_OWNER_EDIT_LOCKED',
            'COMMENT_FIXTURE_OWNER_EDIT_LOCKED: synthetic owner is viewable but editing is locked',
        );

        $locked = $read();
        self::assertFalse($locked['can_write']);
        self::assertSame($source, $locked['source']);
        if ($endpoint === 'threads') {
            self::assertSame([$created['thread_id']], array_column($locked['items'], 'thread_id'));
        } else {
            self::assertSame($created['thread_id'], $locked['thread']['thread_id']);
            self::assertSame([$created['message_id']], array_column($locked['messages'], 'message_id'));
            self::assertSame([11], array_column($locked['messages'], 'actor_member_id'));
        }
        self::assertSame($before, $this->state(), 'Reading a locked owner must not change any source, comment or receipt row');
        self::assertFalse(Db::connect()->getPdo()->inTransaction());

        unset($this->owner->authorizationFailures['edit']);
        $unlocked = $read();
        self::assertTrue($unlocked['can_write']);
        self::assertSame($source, $unlocked['source']);
        self::assertSame($before, $this->state());
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
    }

    public static function readEndpoints(): array
    {
        return ['list' => ['threads'], 'detail' => ['thread']];
    }



    #[DataProvider('readEndpoints')]
    public function testEditScopeMissKeepsFixedRevisionReadable(string $endpoint): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $created = $this->comments->createThread(
            $this->sessions->actor(11),
            $revision['document_id'],
            $revision['revision_id'],
            $this->input($source, 'scope-miss-readable'),
        );
        $reader = $this->sessions->actor(22);
        $read = fn(): array => $endpoint === 'threads'
            ? $this->comments->threads($reader, $revision['document_id'], $revision['revision_id'])
            : $this->comments->thread($reader, $revision['document_id'], $revision['revision_id'], $created['thread_id']);
        $before = $this->state();
        $this->owner->authorizationFailures['edit'] = BusinessException::notFound(
            'COMMENT_FIXTURE_OWNER_EDIT_SCOPE_MISS',
            'COMMENT_FIXTURE_OWNER_EDIT_SCOPE_MISS: synthetic owner is viewable but outside edit scope',
        );

        $restricted = $read();
        self::assertFalse($restricted['can_write']);
        self::assertSame($source, $restricted['source']);
        if ($endpoint === 'threads') {
            self::assertSame([$created['thread_id']], array_column($restricted['items'], 'thread_id'));
        } else {
            self::assertSame($created['thread_id'], $restricted['thread']['thread_id']);
            self::assertSame([$created['message_id']], array_column($restricted['messages'], 'message_id'));
        }
        self::assertSame($before, $this->state());
        self::assertFalse(Db::connect()->getPdo()->inTransaction());

        unset($this->owner->authorizationFailures['edit']);
        $restored = $read();
        self::assertTrue($restored['can_write']);
        self::assertSame($source, $restored['source']);
        self::assertSame($before, $this->state(), 'Restoring edit scope cannot create a comment or change its fixed version');
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
    }

    private function ownerErrorUnchanged(Throwable $expected, callable $operation): void
    {
        $before = $this->state();
        try {
            $operation();
            self::fail('The current owner rejection must propagate');
        } catch (Throwable $actual) {
            self::assertSame($expected, $actual, 'Preserve the original error, including its status and cause');
        }
        self::assertSame($before, $this->state(), 'All source, comment and request rows must remain unchanged');
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
    }

    #[DataProvider('readErrors')]
    public function testReadCapabilityDoesNotHideViewRefusalOrOtherOwnerErrors(string $endpoint, string $action, string $kind): void
    {
        $revision = $this->revision();
        $created = $this->comments->createThread(
            $this->sessions->actor(11),
            $revision['document_id'],
            $revision['revision_id'],
            $this->input($this->source($revision), 'read-error-thread'),
        );
        $this->owner->authorizationFailures['edit'] = BusinessException::conflict('COMMENT_FIXTURE_OWNER_EDIT_LOCKED', 'Synthetic owner editing is locked');
        $error = match ($kind) {
            'unauthenticated' => new BusinessException('COMMENT_FIXTURE_SESSION_EXPIRED', 401, 'Synthetic owner session expired'),
            'forbidden' => BusinessException::forbidden('COMMENT_FIXTURE_OWNER_VIEW_DENIED', 'Synthetic owner view denied'),
            'missing' => BusinessException::notFound('COMMENT_FIXTURE_OWNER_MISSING', 'Synthetic owner is unavailable'),
            'conflict' => BusinessException::conflict('COMMENT_FIXTURE_OWNER_VIEW_LOCKED', 'Synthetic owner view is locked'),
            'server' => new think\exception\HttpException(500, 'Synthetic owner storage unavailable'),
            'program' => new LogicException('SYNTHETIC_OWNER_PROGRAM_FAILURE'),
        };
        $this->owner->authorizationFailures[$action] = $error;
        $reader = $this->sessions->actor(22);
        $this->ownerErrorUnchanged($error, fn(): array => $endpoint === 'threads'
            ? $this->comments->threads($reader, $revision['document_id'], $revision['revision_id'])
            : $this->comments->thread($reader, $revision['document_id'], $revision['revision_id'], $created['thread_id']));
    }

    public static function readErrors(): iterable
    {
        foreach (['threads', 'thread'] as $endpoint) {
            foreach ([
                ['view', 'forbidden'],
                ['view', 'conflict'],
                ['edit', 'unauthenticated'],
                ['view', 'missing'],
                ['edit', 'server'],
                ['edit', 'program'],
            ] as [$action, $kind]) {
                yield $endpoint . '-' . $action . '-' . $kind => [$endpoint, $action, $kind];
            }
        }
    }

    #[DataProvider('editDenials')]
    public function testOwnerStateLockRejectsAllCommandsAndOriginalReceiptsWithoutChangingRows(int $status): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $one = $this->sessions->actor(11);
        $two = $this->sessions->actor(22);
        $documentId = $revision['document_id'];
        $revisionId = $revision['revision_id'];
        $createInput = $this->input($source, 'lock-original-create');
        $created = $this->comments->createThread($one, $documentId, $revisionId, $createInput);
        $replyInput = $this->transition($created, 'lock-original-reply') + ['body' => 'Synthetic second actor reply'];
        $replied = $this->comments->reply($two, $documentId, $revisionId, $replyInput);
        $resolveInput = $this->transition($replied, 'lock-original-resolve');
        $resolved = $this->comments->resolve($two, $documentId, $revisionId, $resolveInput);
        $reopenInput = $this->transition($resolved, 'lock-original-reopen');
        $opened = $this->comments->reopen($one, $documentId, $revisionId, $reopenInput);
        $other = $this->comments->createThread($one, $documentId, $revisionId, $this->input($source, 'lock-closed-thread'));
        $closed = $this->comments->resolve($two, $documentId, $revisionId, $this->transition($other, 'lock-close-thread'));

        $fresh = [
            fn() => $this->comments->createThread($two, $documentId, $revisionId, $this->input($source, 'lock-new-create')),
            fn() => $this->comments->reply($two, $documentId, $revisionId, $this->transition($opened, 'lock-new-reply') + ['body' => 'Must not persist']),
            fn() => $this->comments->resolve($one, $documentId, $revisionId, $this->transition($opened, 'lock-new-resolve')),
            fn() => $this->comments->reopen($two, $documentId, $revisionId, $this->transition($closed, 'lock-new-reopen')),
        ];
        $replays = [
            [$created, fn() => $this->comments->createThread($one, $documentId, $revisionId, $createInput)],
            [$replied, fn() => $this->comments->reply($two, $documentId, $revisionId, $replyInput)],
            [$resolved, fn() => $this->comments->resolve($two, $documentId, $revisionId, $resolveInput)],
            [$opened, fn() => $this->comments->reopen($one, $documentId, $revisionId, $reopenInput)],
        ];
        $before = $this->state();
        $error = self::editDenial($status);
        self::assertSame($status, $error->httpStatus);
        $this->owner->authorizationFailures['edit'] = $error;
        foreach ($fresh as $command) {
            $this->ownerErrorUnchanged($error, $command);
        }
        foreach ($replays as [, $replay]) {
            $this->ownerErrorUnchanged($error, $replay);
        }

        unset($this->owner->authorizationFailures['edit']);
        self::assertTrue($this->comments->threads($two, $documentId, $revisionId)['can_write']);
        foreach ($replays as [$receipt, $replay]) {
            self::assertSame($receipt, $replay(), 'Unlocked original requests recover their original receipts, not a new write');
        }
        self::assertSame($before, $this->state());
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
    }

    #[DataProvider('editDenials')]
    public function testFinalOwnerStateLockRollsBackWriteAndKeepsOriginalRequestRetryable(int $status): void
    {
        $revision = $this->revision();
        $input = $this->input($this->source($revision), 'final-state-lock');
        $actor = $this->sessions->actor(22);
        $error = self::editDenial($status);
        $this->fence->locks = 0;
        $this->fence->onLock = function (int $lock) use ($error): void {
            if ($lock === 2) {
                $this->owner->authorizationFailures['edit'] = $error;
            }
        };
        $command = fn() => $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $input);
        $this->ownerErrorUnchanged($error, $command);
        $this->fence->onLock = null;
        unset($this->owner->authorizationFailures['edit']);
        $created = $command();
        self::assertSame(1, $created['thread_revision']);
        self::assertSame(22, $created['actor_member_id']);
        self::assertCount(1, $this->state()['document_comment_thread']);
        self::assertCount(1, $this->state()['document_comment_message']);
        self::assertCount(1, $this->state()['document_comment_operation']);
    }


    public static function editDenials(): array
    {
        return ['edit-scope-miss' => [404], 'edit-state-lock' => [409]];
    }

    private static function editDenial(int $status): BusinessException
    {
        return match ($status) {
            404 => BusinessException::notFound('COMMENT_FIXTURE_OWNER_EDIT_SCOPE_MISS', 'Synthetic owner is outside current edit scope'),
            409 => BusinessException::conflict('COMMENT_FIXTURE_OWNER_EDIT_LOCKED', 'Synthetic owner editing is locked'),
        };
    }

    #[DataProvider('missingReadTargets')]
    public function testReadStillRejectsMissingDocumentOwnerRevisionOrThread(string $endpoint, string $missing, string $code): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $created = $this->comments->createThread(
            $this->sessions->actor(11),
            $revision['document_id'],
            $revision['revision_id'],
            $this->input($source, 'missing-read-target'),
        );
        $documentId = $revision['document_id'];
        $revisionId = $revision['revision_id'];
        $threadId = $created['thread_id'];
        $before = $this->state();
        if ($missing === 'document') {
            $documentId += 100000;
            self::assertNotContains($documentId, array_column($before['document'], 'id'));
        } elseif ($missing === 'revision') {
            $revisionId += 100000;
            self::assertNotContains($revisionId, array_column($before['document_revision'], 'id'));
        } elseif ($missing === 'thread') {
            $threadId += 100000;
            self::assertNotContains($threadId, array_column($before['document_comment_thread'], 'id'));
        } else {
            // Only this external business-owner absence is synthetic; no Document rows are removed.
            unset($this->owner->objects['1:' . $source['owner']['object_id']]);
        }
        $this->owner->authorizationFailures['edit'] = self::editDenial(404);
        $reader = $this->sessions->actor(22);
        try {
            if ($endpoint === 'threads') {
                $this->comments->threads($reader, $documentId, $revisionId);
            } else {
                $this->comments->thread($reader, $documentId, $revisionId, $threadId);
            }
            self::fail('A missing read target must not become a successful read-only result');
        } catch (BusinessException $error) {
            self::assertSame($code, $error->errorCode);
            self::assertSame(404, $error->httpStatus);
        }
        self::assertSame($before, $this->state(), 'Missing targets cannot change an existing source, thread or receipt');
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
    }

    public static function missingReadTargets(): iterable
    {
        foreach (['threads', 'thread'] as $endpoint) {
            foreach ([
                ['document', 'DOCUMENT_NOT_FOUND'],
                ['owner', 'COMMENT_FIXTURE_OWNER_NOT_FOUND'],
                ['revision', 'DOCUMENT_REVISION_NOT_FOUND'],
            ] as [$missing, $code]) {
                yield $endpoint . '-' . $missing => [$endpoint, $missing, $code];
            }
        }
        yield 'thread-missing' => ['thread', 'thread', 'DOCUMENT_COMMENT_THREAD_NOT_FOUND'];
    }

    #[DataProvider('lateViewRefusals')]
    public function testLockedAndFinalViewScopeChecksStillRejectBeforeReturningComments(string $endpoint, int $guard): void
    {
        $revision = $this->revision();
        $created = $this->comments->createThread(
            $this->sessions->actor(11),
            $revision['document_id'],
            $revision['revision_id'],
            $this->input($this->source($revision), 'late-view-refusal'),
        );
        $this->owner->authorizationFailures['edit'] = self::editDenial(404);
        $error = BusinessException::notFound('COMMENT_FIXTURE_OWNER_VIEW_SCOPE_MISS', 'Synthetic owner is no longer in current view scope');
        $this->fence->locks = 0;
        $this->fence->onLock = function (int $lock) use ($guard, $error): void {
            if ($lock === $guard) {
                $this->owner->authorizationFailures['view'] = $error;
            }
        };
        $reader = $this->sessions->actor(22);
        $this->ownerErrorUnchanged($error, fn(): array => $endpoint === 'threads'
            ? $this->comments->threads($reader, $revision['document_id'], $revision['revision_id'])
            : $this->comments->thread($reader, $revision['document_id'], $revision['revision_id'], $created['thread_id']));
        self::assertSame($guard, $this->fence->locks, 'The rejection must reach the selected native read guard');
    }

    public static function lateViewRefusals(): array
    {
        return [
            'list-locked-view' => ['threads', 1],
            'detail-locked-view' => ['thread', 1],
            'list-final-view' => ['threads', 2],
            'detail-final-view' => ['thread', 2],
        ];
    }

    public function testTwoActorsPersistListReplyResolveAndReopenAgainstRealPublicVersion(): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $before = $this->state();
        self::assertSame("中文😀e\u{0301}\nend", $source['text']);
        self::assertSame(9, $source['text_length']);
        self::assertSame('unicode-code-point', $source['offset_unit']);
        self::assertSame(hash('sha256', $source['text']), $source['text_sha256']);
        $one = $this->sessions->actor(11);
        $two = $this->sessions->actor(22);
        $created = $this->comments->createThread($one, $revision['document_id'], $revision['revision_id'], $this->input($source));
        self::assertSame(11, $created['actor_member_id']);
        $reply = $this->comments->reply(
            $two,
            $revision['document_id'],
            $revision['revision_id'],
            $this->transition($created, 'reply-22') + ['body' => '<b>Plain comment text</b>'],
        );
        self::assertSame(22, $reply['actor_member_id']);
        $resolved = $this->comments->resolve($two, $revision['document_id'], $revision['revision_id'], $this->transition($reply, 'resolve-22'));
        self::assertSame('resolved', $resolved['status']);
        $reopened = $this->comments->reopen($one, $revision['document_id'], $revision['revision_id'], $this->transition($resolved, 'reopen-11'));
        self::assertSame(4, $reopened['thread_revision']);
        $page = $this->comments->threads($two, $revision['document_id'], $revision['revision_id']);
        self::assertTrue($page['can_write']);
        self::assertCount(1, $page['items']);
        $read = $this->comments->thread($two, $revision['document_id'], $revision['revision_id'], $created['thread_id']);
        self::assertSame(['create','reply','resolve','reopen'], array_column($read['messages'], 'action'));
        self::assertSame([11,22,22,11], array_column($read['messages'], 'actor_member_id'));
        self::assertSame([1,2,3,4], array_column($read['messages'], 'thread_revision'));
        self::assertSame('<b>Plain comment text</b>', $read['messages'][1]['body']);
        self::assertNull($read['messages'][2]['body']);
        self::assertSame('中文', $read['thread']['quote']);
        self::assertSame($source, $read['source']);
        $after = $this->state();
        foreach (array_slice(array_keys($before), 0, 6) as $table) {
            self::assertSame($before[$table], $after[$table], 'Comments cannot change body, revision, references or business receipts');
        }
        self::assertCount(4, $after['document_comment_operation']);
        foreach ($this->fence->scopes as [$permissions, $modules]) {
            self::assertNotContains('peanut.collaboration', $modules);
        }
        self::assertContains(['example.notes.edit','example.notes.view'], array_column($this->fence->scopes, 0));
    }

    public function testCodePointRangesPreserveChineseAstralCombiningAndNewline(): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        foreach ([[0,2,'中文'],[2,3,'😀'],[3,4,'e'],[4,5,"\u{0301}"],[3,5,"e\u{0301}"],[5,6,"\n"],[2,9,"😀e\u{0301}\nend"]] as $index => [$start, $end, $quote]) {
            $input = array_replace($this->input($source, 'unicode-' . $index), ['start_offset' => $start,'end_offset' => $end,'quote' => $quote]);
            $result = $this->comments->createThread($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
            $read = $this->comments->thread($this->sessions->actor(22), $revision['document_id'], $revision['revision_id'], $result['thread_id']);
            self::assertSame($quote, $read['thread']['quote']);
            self::assertSame($start, $read['thread']['start_offset']);
            self::assertSame($end, $read['thread']['end_offset']);
        }
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidSourceAndAnchorRejectWithoutChanges(array $change, string $code): void
    {
        $revision = $this->revision();
        $input = array_replace($this->input($this->source($revision)), $change);
        $this->rejected($code, fn() => $this->comments->createThread($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input));
    }
    public static function invalidInputs(): array
    {
        return [
            'negative start' => [['start_offset' => -1], 'DOCUMENT_COMMENT_INPUT_INVALID'],
            'zero span' => [['end_offset' => 0], 'DOCUMENT_COMMENT_RANGE_INVALID'],
            'reverse span' => [['start_offset' => 3,'end_offset' => 2], 'DOCUMENT_COMMENT_RANGE_INVALID'],
            'outside source' => [['end_offset' => 10], 'DOCUMENT_COMMENT_RANGE_INVALID'],
            'UTF16 mistaken emoji end' => [['start_offset' => 2,'end_offset' => 4,'quote' => '😀'], 'DOCUMENT_COMMENT_QUOTE_MISMATCH'],
            'normalized combining mismatch' => [['start_offset' => 3,'end_offset' => 5,'quote' => 'é'], 'DOCUMENT_COMMENT_QUOTE_MISMATCH'],
            'wrong text digest' => [['text_sha256' => str_repeat('0', 64)], 'DOCUMENT_COMMENT_SOURCE_CHANGED'],
            'wrong revision digest' => [['version_digest' => str_repeat('0', 64)], 'DOCUMENT_COMMENT_SOURCE_CHANGED'],
            'wrong profile' => [['text_profile' => 'unbound/1'], 'DOCUMENT_COMMENT_INPUT_INVALID'],
            'wrong quote' => [['quote' => '文中'], 'DOCUMENT_COMMENT_QUOTE_MISMATCH'],
            'blank message' => [['body' => " \n\t"], 'DOCUMENT_COMMENT_INPUT_INVALID'],
            'too long message' => [['body' => str_repeat('a', 4001)], 'DOCUMENT_COMMENT_INPUT_INVALID'],
            'invalid request id' => [['request_id' => 'bad/request'], 'DOCUMENT_COMMENT_INPUT_INVALID'],
            'actor injection' => [['actor_member_id' => 22], 'DOCUMENT_COMMENT_INPUT_INVALID'],
            'tenant injection' => [['tenant_id' => 2], 'DOCUMENT_COMMENT_INPUT_INVALID'],
        ];
    }

    public function testRequestRecoveryRetainsOriginalReceiptAndRejectsChangedActorInputAndProfile(): void
    {
        $revision = $this->revision();
        $input = $this->input($this->source($revision));
        $one = $this->sessions->actor();
        $created = $this->comments->createThread($one, $revision['document_id'], $revision['revision_id'], $input);
        $replyInput = $this->transition($created, 'lost-ack-reply') + ['body' => 'Second'];
        $reply = $this->comments->reply($one, $revision['document_id'], $revision['revision_id'], $replyInput);
        $before = $this->state();
        self::assertSame($created, $this->comments->createThread($one, $revision['document_id'], $revision['revision_id'], $input));
        self::assertSame($reply, $this->comments->reply($one, $revision['document_id'], $revision['revision_id'], $replyInput));
        self::assertSame($before, $this->state());
        $this->rejected('DOCUMENT_COMMENT_REQUEST_CONFLICT', fn() => $this->comments->createThread(
            $one,
            $revision['document_id'],
            $revision['revision_id'],
            array_replace($input, ['body' => 'Changed']),
        ));
        $this->rejected('DOCUMENT_COMMENT_REQUEST_CONFLICT', fn() => $this->comments->createThread(
            $this->sessions->actor(22),
            $revision['document_id'],
            $revision['revision_id'],
            $input,
        ));
        $this->rejected('DOCUMENT_COMMENT_INPUT_INVALID', fn() => $this->comments->createThread(
            $one,
            $revision['document_id'],
            $revision['revision_id'],
            array_replace($input, ['text_profile' => 'peanut.richtext.plain-text/2']),
        ));
        $this->owner->denied['11:edit'] = true;
        $this->rejected('COMMENT_FIXTURE_EDIT_DENIED', fn() => $this->comments->createThread(
            $one,
            $revision['document_id'],
            $revision['revision_id'],
            $input,
        ));
    }

    public function testThreadCasAndStateMachineRejectStaleAndIllegalTransitions(): void
    {
        $revision = $this->revision();
        $actor = $this->sessions->actor();
        $created = $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $this->input($this->source($revision)));
        $this->rejected('DOCUMENT_COMMENT_STATE_CONFLICT', fn() => $this->comments->reopen($actor, $revision['document_id'], $revision['revision_id'], $this->transition($created, 'bad-reopen')));
        $resolved = $this->comments->resolve($actor, $revision['document_id'], $revision['revision_id'], $this->transition($created, 'resolve'));
        $this->rejected('DOCUMENT_COMMENT_REVISION_CONFLICT', fn() => $this->comments->reply($actor, $revision['document_id'], $revision['revision_id'], $this->transition($created, 'stale-reply') + ['body' => 'Stale']));
        $this->rejected('DOCUMENT_COMMENT_STATE_CONFLICT', fn() => $this->comments->reply($actor, $revision['document_id'], $revision['revision_id'], $this->transition($resolved, 'closed-reply') + ['body' => 'Closed']));
        $this->rejected('DOCUMENT_COMMENT_STATE_CONFLICT', fn() => $this->comments->resolve($actor, $revision['document_id'], $revision['revision_id'], $this->transition($resolved, 'double-resolve')));
    }

    public function testCurrentViewEditMemberAndTenantBoundaries(): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $input = $this->input($source);
        $one = $this->sessions->actor();
        $two = $this->sessions->actor(22);
        $this->owner->denied['22:edit'] = true;
        self::assertFalse($this->comments->threads($two, $revision['document_id'], $revision['revision_id'])['can_write']);
        $this->rejected('COMMENT_FIXTURE_EDIT_DENIED', fn() => $this->comments->createThread($two, $revision['document_id'], $revision['revision_id'], $input));
        $this->owner->denied['22:view'] = true;
        $this->rejected('COMMENT_FIXTURE_VIEW_DENIED', fn() => $this->comments->threads($two, $revision['document_id'], $revision['revision_id']));
        $other = $this->revision(33);
        $this->rejected('DOCUMENT_NOT_FOUND', fn() => $this->comments->threads($this->sessions->actor(33), $revision['document_id'], $revision['revision_id']));
        $this->rejected('DOCUMENT_REVISION_NOT_FOUND', fn() => $this->comments->threads($one, $revision['document_id'], $other['revision_id']));
        $this->sessions->revoked[11] = true;
        $this->rejected('DOCUMENT_SESSION_DENIED', fn() => $this->comments->createThread($one, $revision['document_id'], $revision['revision_id'], $input));
        unset($this->sessions->revoked[11]);
        ++$this->sessions->revision;
        $this->rejected('DOCUMENT_AUTHORIZATION_STALE', fn() => $this->comments->createThread($one, $revision['document_id'], $revision['revision_id'], $input));
    }

    public function testFixedRevisionDoesNotReanchorWhenDraftAndCurrentVersionAdvance(): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $actor = $this->sessions->actor();
        $created = $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $this->input($source));
        $next = $this->authorization->run($actor, function (TenantContext $current) use ($revision): array {
            $this->commands->saveSolo($current, $revision['document_id'], 1, self::projection('New body'), 'next-save');
            return $this->commands->freeze($current, $revision['document_id'], 2, 'Next revision', 'next-freeze');
        });
        self::assertSame($source, $this->source($revision));
        self::assertSame([], $this->comments->threads($actor, $revision['document_id'], $next['revision_id'])['items']);
        $this->rejected('DOCUMENT_COMMENT_THREAD_NOT_FOUND', fn() => $this->comments->thread($actor, $revision['document_id'], $next['revision_id'], $created['thread_id']));
        $this->rejected('DOCUMENT_COMMENT_SOURCE_CHANGED', fn() => $this->comments->createThread($actor, $revision['document_id'], $next['revision_id'], $this->input($source, 'wrong-fixed')));
        $other = $this->revision();
        $this->rejected('DOCUMENT_COMMENT_THREAD_NOT_FOUND', fn() => $this->comments->thread($actor, $other['document_id'], $other['revision_id'], $created['thread_id']));
    }

    #[DataProvider('persistentChanges')]
    public function testPersistentOwnerAndSourceCorruptionRejectWithExactReason(string $change, string $code): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $created = $this->comments->createThread($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $this->input($source));
        // Negative SQL fact injection only. Positive comments and immutable versions are never seeded.
        if ($change === 'owner') {
            $this->owner->add(1, 'changed-owner');
            Db::name('document')->where('id', $revision['document_id'])->update(['owner_object_id' => 'changed-owner']);
        } elseif ($change === 'digest') {
            Db::name('document_revision')->where('id', $revision['revision_id'])->update(['snapshot_sha256' => str_repeat('0', 64)]);
        } elseif ($change === 'content') {
            Db::name('document_revision')->where('id', $revision['revision_id'])->update(['content_snapshot_json' => json_encode(self::projection('Tampered'), JSON_THROW_ON_ERROR)]);
        } elseif ($change === 'archive') {
            $this->owner->objects['1:note-1']['archived'] = true;
        }
        $this->rejected($code, fn() => $this->comments->thread($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $created['thread_id']));
    }
    public static function persistentChanges(): array
    {
        return [['owner','DOCUMENT_COMMENT_OWNER_CHANGED'], ['digest','DOCUMENT_VERSION_DIGEST_INVALID'],
            ['content','DOCUMENT_VERSION_DIGEST_INVALID'], ['archive','DOCUMENT_STATE_DENIED']];
    }

    public function testFinalCurrentOwnerRecheckRollsBackAndOriginalRequestCanRetry(): void
    {
        $revision = $this->revision();
        $input = $this->input($this->source($revision), 'retry-after-rollback');
        $actor = $this->sessions->actor();
        $this->fence->locks = 0;
        $this->fence->onLock = function (int $lock): void {
            if ($lock === 2) {
                $this->owner->denied['11:edit'] = true;
            }
        };
        $this->rejected('COMMENT_FIXTURE_EDIT_DENIED', fn() => $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $input));
        self::assertSame(2, $this->fence->locks);
        $this->fence->onLock = null;
        unset($this->owner->denied['11:edit']);
        $created = $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame(1, $created['thread_revision']);
        self::assertCount(1, $this->state()['document_comment_operation']);
        self::assertSame($created, $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $input));
    }

    public function testPersistenceFailureRollsBackAllRowsAndOriginalRequestCanRetry(): void
    {
        $revision = $this->revision();
        $input = $this->input($this->source($revision), 'retry-storage-failure');
        $before = $this->state();
        Db::execute("CREATE TRIGGER comment_receipt_failure BEFORE INSERT ON pa_document_comment_operation BEGIN SELECT RAISE(ABORT, 'SYNTHETIC_STORAGE_FAILURE'); END");
        try {
            $this->comments->createThread($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
            self::fail('Storage error was hidden');
        } catch (think\db\exception\PDOException $error) {
            self::assertStringContainsString('SYNTHETIC_STORAGE_FAILURE', $error->getMessage());
        }
        self::assertSame($before, $this->state());
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
        Db::execute('DROP TRIGGER comment_receipt_failure');
        $created = $this->comments->createThread($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame(1, $created['thread_revision']);
        self::assertCount(1, $this->state()['document_comment_thread']);
        self::assertCount(1, $this->state()['document_comment_message']);
        self::assertCount(1, $this->state()['document_comment_operation']);
    }

    public function testPaginationDoesNotMixThreadsAndHistoryRemainsAppendOnly(): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $actor = $this->sessions->actor();
        $created = [];
        for ($i = 0; $i < 3; ++$i) {
            $created[] = $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $this->input($source, 'page-' . $i));
        }
        $page = $this->comments->threads($actor, $revision['document_id'], $revision['revision_id'], 2);
        self::assertSame([$created[2]['thread_id'],$created[1]['thread_id']], array_column($page['items'], 'thread_id'));
        $next = $this->comments->threads($actor, $revision['document_id'], $revision['revision_id'], 2, $page['next_before_thread_id']);
        self::assertSame([$created[0]['thread_id']], array_column($next['items'], 'thread_id'));
        self::assertNull($next['next_before_thread_id']);
        $reply = $this->comments->reply($actor, $revision['document_id'], $revision['revision_id'], $this->transition($created[0], 'page-reply') + ['body' => 'Reply']);
        $history = $this->comments->thread($actor, $revision['document_id'], $revision['revision_id'], $created[0]['thread_id'], 1);
        $last = $this->comments->thread($actor, $revision['document_id'], $revision['revision_id'], $created[0]['thread_id'], 1, $history['next_after_message_id']);
        self::assertSame($reply['message_id'], $last['messages'][0]['message_id']);
        self::assertNull($last['next_after_message_id']);
        $this->rejected('DOCUMENT_COMMENT_CURSOR_INVALID', fn() => $this->comments->thread($actor, $revision['document_id'], $revision['revision_id'], $created[0]['thread_id'], 1, $created[1]['message_id']));
        foreach ([DocumentCommentMessage::class, DocumentCommentOperation::class] as $class) {
            $this->rejected('DOCUMENT_COMMENT_HISTORY_IMMUTABLE', fn() => $this->authorization->run($actor, fn() => $class::where('id', 1)->find()->save(['created_at' => '2026-11-02 00:00:00.000'])));
            $this->rejected('DOCUMENT_COMMENT_HISTORY_IMMUTABLE', fn() => $this->authorization->run($actor, fn() => $class::where('id', 1)->find()->delete()));
        }
    }

    public function testRealControllerUsesCurrentActorAndRejectsIdentityOverride(): void
    {
        $revision = $this->revision();
        $input = $this->input($this->source($revision), 'controller-comment') + [
            'document_id' => $revision['document_id'], 'revision_id' => $revision['revision_id'],
        ];
        $actor = $this->sessions->actor(22);
        $call = function (array $body) use ($actor): array {
            $this->app->instance(think\Request::class, (new think\Request())->withPost($body));
            return $this->authorization->run($actor, fn() => (new DocumentRevisionCommentController($this->app))->createThread()->getData()['data']);
        };
        $this->rejected('DOCUMENT_COMMENT_INPUT_INVALID', fn() => $call($input + ['actor_member_id' => 11]));
        $result = $call($input);
        self::assertSame(22, $result['actor_member_id']);
        self::assertSame($revision['revision_id'], $result['revision_id']);
        self::assertSame($result, $call($input));
    }

    #[DataProvider('finalChanges')]
    public function testFinalMemberOrSourceRecheckRollsBackTheEntireCommand(string $change, string $code): void
    {
        $revision = $this->revision();
        $input = $this->input($this->source($revision), 'final-recheck');
        $actor = $this->sessions->actor();
        $this->fence->locks = 0;
        $this->fence->onLock = function (int $lock) use ($change, $revision): void {
            if ($lock !== 2) {
                return;
            }
            if ($change === 'session') {
                $this->sessions->revoked[11] = true;
            } else {
                Db::name('document_revision')->where('id', $revision['revision_id'])->update(['snapshot_sha256' => str_repeat('0', 64)]);
            }
        };
        $this->rejected($code, fn() => $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $input));
        $this->fence->onLock = null;
        unset($this->sessions->revoked[11]);
        $created = $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame(1, $created['thread_revision']);
        self::assertCount(1, $this->state()['document_comment_operation']);
    }
    public static function finalChanges(): array
    {
        return [['session', 'DOCUMENT_SESSION_DENIED'], ['source', 'DOCUMENT_VERSION_DIGEST_INVALID']];
    }

    public function testExistingPreparedVersionAndSaveBoundaryCannotBeBypassedByComments(): void
    {
        $revision = $this->revision();
        $actor = $this->sessions->actor();
        $source = $this->source($revision);
        $owner = new PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference(
            $source['owner']['module_key'],
            $source['owner']['object_type'],
            $source['owner']['object_id'],
            $source['owner']['slot'],
        );
        $outside = $this->queries->versionSnapshot($actor, $revision['document_id'], $revision['revision_id']);
        $this->rejected('DOCUMENT_PREPARED_VERSION_DENIED', fn() => $this->authorization->run(
            $actor,
            fn() => Db::transaction(fn() => $this->queries->verifyPreparedVersion($actor, $outside, $owner)),
        ));
        $this->rejected('DOCUMENT_VERSION_NOT_COMMITTED', fn() => $this->authorization->run(
            $actor,
            fn() => Db::transaction(fn() => $this->queries->versionSnapshot($actor, $revision['document_id'], $revision['revision_id'])),
        ));
        $before = $this->state();
        try {
            $this->authorization->run($actor, fn() => Db::transaction(fn() => $this->comments->createThread(
                $actor,
                $revision['document_id'],
                $revision['revision_id'],
                $this->input($source, 'outer-transaction'),
            )));
            self::fail('A caller-owned transaction was accepted');
        } catch (DomainException $error) {
            self::assertSame('IDENTITY_AUTHORIZATION_SNAPSHOT_TRANSACTION_DENIED', $error->getMessage());
        }
        self::assertSame($before, $this->state());
        $this->authorization->run($actor, function (TenantContext $current) use ($revision, $owner): void {
            $prepared = $this->queries->ownerSaveSnapshot($current, $revision['document_id'], $revision['revision_id'], $owner);
            $this->commands->saveSolo($current, $revision['document_id'], 1, self::projection('Changed after prepare'), 'save-after-prepare');
            $this->rejected(
                'DOCUMENT_SAVE_STATE_CHANGED',
                fn() => Db::transaction(fn() => $this->queries->verifyPreparedVersion($current, $prepared, $owner)),
            );
        });
        self::assertSame($source, $this->source($revision), 'View-bound historical comments retain the actual immutable source');
    }
}
