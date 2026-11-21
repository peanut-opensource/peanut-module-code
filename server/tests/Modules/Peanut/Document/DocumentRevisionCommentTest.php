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
    private DocumentCommentFixture\Audit $audit;
    private string $directory;
    private int $sequence = 0;
    private array $disabled = [];
    private bool $collaborationEnabled = false;
    private PeanutAdmin\Modules\Document\Contract\DocumentAcceptedWriteProvider $collaboration;

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
            $server . '/app/modules/peanut/document/database/migrations/20261116000007_document_revision_suggestions.php',
        );
        DocumentCommentFixture\schema(
            $server . '/app/modules/peanut/document/database/migrations/20261116000007_document_revision_suggestions.php',
            ['document_change'],
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
            ($key === 'peanut.collaboration' && !$this->collaborationEnabled) || isset($this->disabled[$key]) ? 'disabled' : 'enabled',
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
        $this->collaboration = $this->createStub(PeanutAdmin\Modules\Document\Contract\DocumentAcceptedWriteProvider::class);
        $this->app->instance($this->collaboration::class, $this->collaboration);
        $documents = new DocumentService(
            $this->authorization,
            $references,
            $this->createStub(FileDocumentSnapshots::class),
            $this->createStub(FileStorage::class),
            $this->audit = new DocumentCommentFixture\Audit(),
            $unused(PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService::class),
            $contexts,
            new PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry($this->app, $this->collaboration::class),
            $owners,
            $this->fence,
            $modules,
        );
        $this->commands = $documents;
        $this->queries = $documents;
        $this->comments = new DocumentRevisionCommentService($this->authorization, $documents, $owners, $this->fence, $documents, $this->audit, $references);
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
            'document_comment_thread','document_comment_message','document_comment_operation','document_change'] as $table) {
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


    /** Real Document orchestration/rows; the external collaboration transport and Yjs capture alone are synthetic. */
    private function collaborativeSuggestion(string $failure): array
    {
        $revision = $this->revision();
        $created = $this->suggestion($revision);
        $this->collaborationEnabled = true;
        Db::name('document')->where('id', $revision['document_id'])->update(['collaboration_enabled' => 1, 'lease_epoch' => 1]);
        $baselineBinary = 'synthetic-external-yjs-baseline';
        Db::name('document_state')->where('document_id', $revision['document_id'])->update([
            'yjs_state_binary' => $baselineBinary, 'state_sha256' => hash('sha256', $baselineBinary),
        ]);
        $boundary = (object) ['failure' => $failure, 'prepare' => 0, 'drain' => 0, 'apply' => 0, 'consume' => 0, 'release' => 0];
        $this->collaboration->method('prepareBusinessBarrier')->willReturnCallback(function ($actor, $id, $owner, $action, $operation, $hash, $seq, $auth, $ownerRevision) use ($boundary) {
            self::assertTrue(Db::connect()->getPdo()->inTransaction());
            ++$boundary->prepare;
            $doc = Db::name('document')->where('id', $id)->find();
            return new PeanutAdmin\Modules\Document\Contract\BusinessBarrierPlan([
                'protocol' => 2, 'binding' => ['instance_id' => 'synthetic-comments', 'edition' => 'multi-tenant',
                    'tenant_id' => $actor->tenantId, 'document_id' => $id,
                    'owner' => ['module_key' => $owner->moduleKey, 'object_type' => $owner->objectType, 'object_id' => $owner->objectId, 'slot' => $owner->slot]],
                'operation_id' => $operation, 'action' => $action, 'input_sha256' => $hash,
                'barrier_key' => str_repeat('b', 64), 'room_key' => str_repeat('c', 64),
                'service_instance_id' => 'synthetic-collaboration', 'control_mode' => 'native',
                'holder_key' => '00000000-0000-4000-8000-000000000001', 'lease_epoch' => (int) $doc['lease_epoch'],
                'base_state_seq' => $seq, 'base_auth_revision' => $auth, 'owner_revision' => $ownerRevision, 'deadline' => time() + 60,
            ]);
        });
        $this->collaboration->method('drainBusinessBarrier')->willReturnCallback(static function ($plan) use ($boundary): void {
            self::assertFalse(Db::connect()->getPdo()->inTransaction());
            ++$boundary->drain;
            if (in_array($boundary->failure, ['COLLABORATION_CONTROL_UNCONFIRMED', 'COLLABORATION_HOLDER_CHANGED'], true)) {
                throw BusinessException::conflict($boundary->failure, 'Synthetic external drain refusal');
            }
        });
        $this->collaboration->method('lockDrainedBusinessBarrier')->willReturnCallback(static function ($actor, $plan) use ($baselineBinary, $boundary) {
            self::assertTrue(Db::connect()->getPdo()->inTransaction());
            if (str_starts_with($boundary->failure, 'progress')) throw BusinessException::conflict('COLLABORATION_BARRIER_NOT_DURABLE', 'Synthetic native loaded-progress mismatch');
            return new PeanutAdmin\Modules\Document\Contract\DrainedBusinessBarrier($plan, $plan->data['base_state_seq'], hash('sha256', $baselineBinary), null, 0);
        });
        $this->collaboration->method('lockUnsentSuggestionCancellation')->willReturnCallback(static fn($actor, $plan) =>
            new PeanutAdmin\Modules\Document\Contract\UnsentSuggestionCancellation($plan, $plan->data['base_state_seq'], hash('sha256', $baselineBinary), 'store:previous-room', 1));
        $this->collaboration->method('consumeUnsentSuggestionCancellation')->willReturnCallback(static function ($proof, $receipt) use ($boundary): void {
            self::assertTrue(Db::connect()->getPdo()->inTransaction());
            self::assertTrue($receipt->data['cancelled']);
            self::assertArrayNotHasKey('revision_id', $receipt->data);
            self::assertSame($proof->stateSeq, $receipt->data['state_seq']);
            self::assertSame($proof->binarySha256, $receipt->data['binary_sha256']);
            ++$boundary->consume;
        });
        $this->collaboration->method('applySuggestion')->willReturnCallback(static function ($plan, $candidate, $baselineHash) use ($boundary, $baselineBinary) {
            self::assertFalse(Db::connect()->getPdo()->inTransaction());
            ++$boundary->apply;
            if (in_array($boundary->failure, ['cancel', 'cancel-release'], true)) throw BusinessException::conflict('SUGGESTION_BASELINE_CONFLICT', 'Synthetic signed pre-mutation refusal');
            $projection = RichTextProjection::fromStructured($candidate);
            $binary = 'synthetic-external-yjs-applied';
            return new PeanutAdmin\Modules\Document\Contract\SuggestionAppliedCapture($plan, [
                'protocol' => 2, 'operation_id' => $plan->data['operation_id'], 'input_sha256' => $plan->data['input_sha256'],
                'room_key' => $plan->data['room_key'], 'holder_key' => $plan->data['holder_key'], 'lease_epoch' => $plan->data['lease_epoch'],
                'phase' => 'suggestion_applied', 'base_state_seq' => $plan->data['base_state_seq'], 'actor_member_id' => 11,
                'structured_content' => $candidate, 'baseline_binary_sha256' => hash('sha256', $baselineBinary),
                'baseline_projection_sha256' => $baselineHash, 'binary_sha256' => hash('sha256', $binary),
                'projection_sha256' => hash('sha256', $projection->json),
                'reference_digest' => PeanutAdmin\Modules\Document\Contract\AcceptedStoreInput::referencesDigest($projection->fileReferences),
                'binary_base64' => base64_encode($binary), 'snapshot_base64' => base64_encode('synthetic-external-yjs-snapshot'),
            ]);
        });
        $this->collaboration->method('verifySuggestionCapture')->willReturnCallback(function ($actor, $capture) use ($boundary): void {
            self::assertTrue(Db::connect()->getPdo()->inTransaction());
            if ($boundary->failure === 'final-edit') $this->owner->denied['11:edit'] = true;
        });
        $this->collaboration->method('consumeBusinessBarrier')->willReturnCallback(static function ($proof, $receipt) use ($boundary): void {
            self::assertTrue(Db::connect()->getPdo()->inTransaction());
            ++$boundary->consume;
        });
        $this->collaboration->method('fencedReferenceReservations')->willReturn([]);
        $this->collaboration->method('releaseBusinessBarrier')->willReturnCallback(static function ($plan, $receipt) use ($boundary): void {
            self::assertFalse(Db::connect()->getPdo()->inTransaction());
            ++$boundary->release;
            if (in_array($boundary->failure, ['release', 'cancel-release', 'progress-release'], true)) throw BusinessException::conflict('COLLABORATION_RELEASE_UNCONFIRMED', 'Synthetic external lost release');
        });
        return [$revision, $created, $this->transition($created, 'collaboration-accept') + ['expected_state_seq' => 1], $boundary];
    }

    public function testLoadedProgressCancellationKeepsOriginalRequestUntilReleaseAckAndNeverApplies(): void
    {
        [$revision, $created, $input, $boundary] = $this->collaborativeSuggestion('progress-release');
        $before = $this->state();
        $this->unconfirmedSuggestion($revision, $input, 'COLLABORATION_RELEASE_UNCONFIRMED');
        self::assertSame(0, $boundary->apply);
        self::assertSame(1, $boundary->consume);
        $after = $this->state();
        self::assertSame($before['document_state'], $after['document_state']);
        self::assertSame($before['document_revision'], $after['document_revision']);
        self::assertSame($before['document_change'], $after['document_change']);
        self::assertSame('pending', $after['document_comment_thread'][0]['decision']);
        self::assertSame($before['document_comment_thread'][0]['candidate_json'], $after['document_comment_thread'][0]['candidate_json']);
        $op = array_values(array_filter($after['document_operation'], static fn($row) => $row['action'] === 'suggestion.accept'))[0];
        self::assertSame('completed', $op['phase']);
        self::assertNull($op['released_at']);
        self::assertTrue(json_decode($op['result_json'], true, 64, JSON_THROW_ON_ERROR)['cancelled']);
        $original = $op['command_input_json'];
        $boundary->failure = 'progress';
        try {
            $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
            self::fail('Cancellation is exposed only after its original release ACK');
        } catch (BusinessException $error) {
            self::assertSame(PeanutAdmin\Modules\Document\Contract\UnsentSuggestionCancellation::CODE, $error->errorCode);
        }
        $op = Db::name('document_operation')->where('document_id', $revision['document_id'])->where('action', 'suggestion.accept')->find();
        self::assertNotNull($op['released_at']);
        self::assertSame($original, $op['command_input_json']);
        self::assertSame(1, $boundary->prepare);
        self::assertSame(1, $boundary->drain);
        self::assertSame(0, $boundary->apply);
        self::assertSame(2, $boundary->release);
    }

    /** Real PHP provider/Document rows; only the original native drain receipt is synthetic. */
    private function nativeLoadedProgressFixture(): array
    {
        [$revision, , $input] = $this->collaborativeSuggestion('COLLABORATION_CONTROL_UNCONFIRMED');
        $this->unconfirmedSuggestion($revision, $input, 'COLLABORATION_CONTROL_UNCONFIRMED');
        $server = dirname(__DIR__, 4);
        DocumentCommentFixture\schema($server . '/app/modules/peanut/collaboration/database/migrations/20261102000008_initial_collaboration_schema.php',
            ['collaboration_room','collaboration_connection','collaboration_barrier']);
        $op = Db::name('document_operation')->where('document_id', $revision['document_id'])->where('action', 'suggestion.accept')->find();
        $plan = new PeanutAdmin\Modules\Document\Contract\BusinessBarrierPlan(json_decode($op['barrier_plan_json'], true, 64, JSON_THROW_ON_ERROR));
        $state = Db::name('document_state')->where('document_id', $revision['document_id'])->find();
        $receipt = ['document_id' => $revision['document_id'],'store_id' => 'store:previous-room','lease_epoch' => $plan->data['lease_epoch'],
            'state_seq' => (int) $state['state_seq'],'binary_sha256' => $state['state_sha256'],'target_progress' => 1];
        Db::name('document_operation')->insert(['tenant_id' => 1,'document_id' => $revision['document_id'],'operation_id' => $receipt['store_id'],
            'action' => 'collaboration.store','input_sha256' => str_repeat('a', 64),'result_json' => json_encode($receipt, JSON_THROW_ON_ERROR),'created_at' => '2026-11-16 00:00:00']);
        Db::name('document_state')->where('document_id', $revision['document_id'])->update(['last_store_id' => $receipt['store_id']]);
        $roomId = Db::name('collaboration_room')->insertGetId(['tenant_id' => 1,'instance_id' => $plan->data['binding']['instance_id'],
            'edition' => 'multi-tenant','document_id' => $revision['document_id'],'room_key' => $plan->data['room_key'],
            'binding_json' => json_encode($plan->data['binding'], JSON_THROW_ON_ERROR),'service_instance_id' => $plan->data['service_instance_id'],
            'holder_key' => $plan->data['holder_key'],'lease_epoch' => $plan->data['lease_epoch'],'lease_expires_at' => '2026-11-16 00:01:00',
            'status' => 'draining','persisted_state_seq' => $state['state_seq'],'baseline_sha256' => $state['state_sha256'],
            'created_at' => '2026-11-16 00:00:00','updated_at' => '2026-11-16 00:00:00','protocol_version' => 2]);
        $reply = array_intersect_key($plan->data, array_flip(['protocol','operation_id','input_sha256','room_key','holder_key','lease_epoch']))
            + ['phase' => 'drained','store_id' => $receipt['store_id'],'state_seq' => (int) $state['state_seq'],
                'binary_sha256' => $state['state_sha256'],'target_progress' => 0,'snapshot_base64' => base64_encode('synthetic-native-snapshot')];
        Db::name('collaboration_barrier')->insert(['tenant_id' => 1,'room_id' => $roomId,'barrier_key' => $plan->data['barrier_key'],
            'lease_epoch' => $plan->data['lease_epoch'],'purpose' => 'version','status' => 'draining','input_sha256' => $plan->hash(),
            'business_plan_json' => json_encode($plan->data, JSON_THROW_ON_ERROR),'drained_receipt_json' => json_encode($reply, JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-16 00:00:00','expires_at' => '2026-11-16 00:01:00']);
        $class = new ReflectionClass(PeanutAdmin\Modules\Collaboration\Service\NativeCollaborationProvider::class);
        $provider = $class->newInstanceWithoutConstructor();
        $contexts = $this->app->make(ExecutionContextStore::class);
        foreach (['execution' => $contexts,'commits' => new PeanutAdmin\Modules\Document\Service\DocumentAcceptedCommitQuery($contexts),'businessProofs' => new WeakMap()] as $name => $value) {
            $class->getProperty($name)->setValue($provider, $value);
        }
        return [$provider, $plan, $reply, $revision];
    }

    public function testNativeLoadedProgressProofOnlyCancelsUnchangedBytesAndSequence(): void
    {
        [$provider, $plan, $reply, $revision] = $this->nativeLoadedProgressFixture();
        $before = $this->state();
        $this->authorization->run($this->sessions->actor(), function (TenantContext $actor) use ($provider, $plan, $reply): void {
            Db::transaction(function () use ($actor, $provider, $plan, $reply): void {
                try {
                    $provider->lockDrainedBusinessBarrier($actor, $plan);
                    self::fail('Ordinary accepted-write proof must still reject the progress mismatch');
                } catch (BusinessException $error) {
                    self::assertSame('COLLABORATION_BARRIER_NOT_DURABLE', $error->errorCode);
                }
                $proof = $provider->lockUnsentSuggestionCancellation($actor, $plan);
                self::assertNotInstanceOf(PeanutAdmin\Modules\Document\Contract\DrainedBusinessBarrier::class, $proof);
                $result = ['document_id' => $plan->data['binding']['document_id'],'content_kind' => 'rich_text','schema_version' => RichTextProjection::SCHEMA,
                    'status' => 'active','state_seq' => $proof->stateSeq,'auth_revision' => $plan->data['base_auth_revision'] + 1,
                    'operation_id' => $plan->data['operation_id'],'phase' => 'completed','lease_epoch' => $plan->data['lease_epoch'] + 1,
                    'binary_sha256' => $proof->binarySha256,'store_id' => $proof->storeId,'target_progress' => $proof->targetProgress,
                    'cancelled' => true,'conflict_code' => PeanutAdmin\Modules\Document\Contract\UnsentSuggestionCancellation::CODE];
                $receipt = new PeanutAdmin\Modules\Document\Contract\DocumentBusinessReceipt($plan, $result);
                $forged = new PeanutAdmin\Modules\Document\Contract\UnsentSuggestionCancellation($plan, $proof->stateSeq, $proof->binarySha256, $proof->storeId, $proof->targetProgress);
                try {
                    $provider->consumeUnsentSuggestionCancellation($forged, $receipt);
                    self::fail('Constructing a DTO cannot issue a consumable proof');
                } catch (BusinessException $error) {
                    self::assertSame('DOCUMENT_SUGGESTION_CANCELLATION_ONLY', $error->errorCode);
                }
                $accepted = $result;
                unset($accepted['cancelled'], $accepted['conflict_code']);
                $accepted += ['revision_id' => 99,'revision_no' => 2,'source_state_seq' => $proof->stateSeq,'digest' => str_repeat('a', 64)];
                try {
                    $provider->consumeUnsentSuggestionCancellation($proof, new PeanutAdmin\Modules\Document\Contract\DocumentBusinessReceipt($plan, $accepted));
                    self::fail('Cancellation proof cannot consume an accepted revision');
                } catch (BusinessException $error) {
                    self::assertSame('DOCUMENT_SUGGESTION_CANCELLATION_ONLY', $error->errorCode);
                }
                foreach (['state_seq' => $proof->stateSeq + 1,'binary_sha256' => str_repeat('f', 64)] as $field => $changed) {
                    try {
                        $provider->consumeUnsentSuggestionCancellation($proof, new PeanutAdmin\Modules\Document\Contract\DocumentBusinessReceipt($plan, [$field => $changed] + $result));
                        self::fail('Cancellation cannot change ' . $field);
                    } catch (BusinessException $error) {
                        self::assertSame('DOCUMENT_SUGGESTION_CANCELLATION_ONLY', $error->errorCode);
                    }
                }
                Db::name('document')->where('id', $result['document_id'])->update(['auth_revision' => $result['auth_revision'],'lease_epoch' => $result['lease_epoch']]);
                Db::name('document_operation')->where('operation_id', $plan->data['operation_id'])->update(['phase' => 'completed','result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
                foreach (['suggestion_command_sha256','suggestion_capture','suggestion_rejected'] as $key) {
                    Db::name('collaboration_barrier')->where('barrier_key', $plan->data['barrier_key'])->update(['drained_receipt_json' => json_encode([$key => null] + $reply, JSON_THROW_ON_ERROR)]);
                    try {
                        $provider->consumeUnsentSuggestionCancellation($proof, $receipt);
                        self::fail('A dispatched command cannot consume a cancellation proof');
                    } catch (BusinessException $error) {
                        self::assertSame('DOCUMENT_SUGGESTION_APPLICATION_UNCONFIRMED', $error->errorCode);
                    }
                }
                Db::name('collaboration_barrier')->where('barrier_key', $plan->data['barrier_key'])->update(['drained_receipt_json' => json_encode($reply, JSON_THROW_ON_ERROR)]);
                $provider->consumeUnsentSuggestionCancellation($proof, $receipt);
                $barrier = Db::name('collaboration_barrier')->where('barrier_key', $plan->data['barrier_key'])->find();
                self::assertSame($reply, json_decode($barrier['drained_receipt_json'], true, 64, JSON_THROW_ON_ERROR));
                self::assertTrue(json_decode($barrier['business_result_json'], true, 64, JSON_THROW_ON_ERROR)['cancelled']);
                try {
                    $provider->consumeUnsentSuggestionCancellation($proof, $receipt);
                    self::fail('The same proof is single use');
                } catch (BusinessException $error) {
                    self::assertSame('DOCUMENT_SUGGESTION_CANCELLATION_ONLY', $error->errorCode);
                }
            });
        });
        $after = $this->state();
        foreach (['document_state','document_revision','document_change'] as $table) self::assertSame($before[$table], $after[$table]);
    }

    #[DataProvider('nativeProgressCancellationRefusals')]
    public function testNativeLoadedProgressCancellationRejectsAnyOtherMismatch(string $change): void
    {
        [$provider, $plan, $reply, $revision] = $this->nativeLoadedProgressFixture();
        if ($change === 'other-action') $plan = new PeanutAdmin\Modules\Document\Contract\BusinessBarrierPlan(['action' => 'version.restore'] + $plan->data);
        if (in_array($change, ['suggestion_command_sha256','suggestion_capture','suggestion_rejected'], true)) $reply[$change] = null;
        if ($change === 'drained-progress') $reply['target_progress'] = 1;
        if ($change === 'drained-sequence') ++$reply['state_seq'];
        if ($change === 'drained-binary') $reply['binary_sha256'] = str_repeat('f', 64);
        Db::name('collaboration_barrier')->where('barrier_key', $plan->data['barrier_key'])->update(['drained_receipt_json' => json_encode($reply, JSON_THROW_ON_ERROR)]);
        $store = Db::name('document_operation')->where('operation_id', $reply['store_id'])->find();
        $receipt = json_decode($store['result_json'], true, 64, JSON_THROW_ON_ERROR);
        if ($change === 'store-progress') $receipt['target_progress'] = 0;
        if ($change === 'store-epoch') ++$receipt['lease_epoch'];
        Db::name('document_operation')->where('operation_id', $reply['store_id'])->update(['result_json' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
        if ($change === 'store-action') Db::name('document_operation')->where('operation_id', $reply['store_id'])->update(['action' => 'collaboration.baseline']);
        if ($change === 'actual-bytes') Db::name('document_state')->where('document_id', $revision['document_id'])->update(['yjs_state_binary' => 'changed']);
        if ($change === 'room-baseline') Db::name('collaboration_room')->where('room_key', $plan->data['room_key'])->update(['baseline_sha256' => str_repeat('f', 64)]);
        $before = $this->state();
        $this->authorization->run($this->sessions->actor($change === 'other-actor' ? 22 : 11), function (TenantContext $actor) use ($provider, $plan, $change): void {
            Db::transaction(function () use ($actor, $provider, $plan, $change): void {
                try {
                    $provider->lockUnsentSuggestionCancellation($actor, $plan);
                    self::fail('Only the confirmed pre-dispatch loaded-progress defect is cancelable');
                } catch (BusinessException $error) {
                    self::assertSame(in_array($change, ['other-action','other-actor'], true) ? 'DOCUMENT_SUGGESTION_COMMAND_INVALID' : 'COLLABORATION_BARRIER_NOT_DURABLE', $error->errorCode);
                }
            });
        });
        self::assertSame($before, $this->state());
        self::assertSame('draining', Db::name('collaboration_barrier')->value('status'));
    }

    public static function nativeProgressCancellationRefusals(): array
    {
        return array_map(static fn($case) => [$case], ['suggestion_command_sha256','suggestion_capture','suggestion_rejected',
            'drained-progress','drained-sequence','drained-binary','store-progress','store-epoch','store-action','actual-bytes','room-baseline','other-action','other-actor']);
    }

    public function testLoadedBaselineUsesAStableZeroProgressReceiptInsteadOfOldStoreProgress(): void
    {
        [, $plan] = $this->nativeLoadedProgressFixture();
        $before = $this->state();
        $this->authorization->run($this->sessions->actor(), function () use ($plan): void {
            Db::transaction(function () use ($plan): void {
                $doc = PeanutAdmin\Modules\Document\Model\Document::find($plan->data['binding']['document_id']);
                $state = PeanutAdmin\Modules\Document\Model\DocumentState::where('document_id', $doc->id)->find();
                $baseline = new ReflectionMethod(DocumentService::class, 'baselineReceipt');
                $storeId = $baseline->invoke($this->commands, $doc, $state);
                self::assertStringStartsWith('baseline:', $storeId);
                self::assertSame($storeId, $baseline->invoke($this->commands, $doc, $state));
                $op = Db::name('document_operation')->where('operation_id', $storeId)->find();
                self::assertSame('collaboration.baseline', $op['action']);
                $receipt = json_decode($op['result_json'], true, 64, JSON_THROW_ON_ERROR);
                self::assertSame(0, $receipt['target_progress']);
                self::assertSame((int) $state->state_seq, $receipt['state_seq']);
                self::assertSame($state->state_sha256, $receipt['binary_sha256']);
            });
        });
        $after = $this->state();
        foreach (['document','document_state','document_revision','document_change'] as $table) self::assertSame($before[$table], $after[$table]);
    }

    private function unconfirmedSuggestion(array $revision, array $input, string $reason): void
    {
        try {
            $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
            self::fail('Expected original application uncertainty');
        } catch (BusinessException $error) {
            self::assertSame('DOCUMENT_SUGGESTION_APPLICATION_UNCONFIRMED', $error->errorCode);
            self::assertStringContainsString($reason, $error->getMessage());
        }
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
    }

    #[DataProvider('collaborationUncertainDrainReasons')]
    public function testCollaborativeSuggestionDrainUncertaintyRetainsOriginalRequest(string $reason): void
    {
        [$revision, $created, $input, $boundary] = $this->collaborativeSuggestion($reason);
        $this->unconfirmedSuggestion($revision, $input, $reason);
        self::assertSame(1, $boundary->prepare);
        self::assertSame(0, $boundary->apply);
        $state = $this->state();
        self::assertCount(1, $state['document_revision']);
        self::assertSame('pending', $state['document_comment_thread'][0]['decision']);
        self::assertSame($input['request_id'], $state['document_comment_thread'][0]['accept_request_id']);
        $op = array_values(array_filter($state['document_operation'], static fn($row) => $row['action'] === 'suggestion.accept'))[0];
        self::assertSame('attention', $op['phase']);
        self::assertNull($op['released_at']);
        $boundary->failure = '';
        $accepted = $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame('accepted', $accepted['suggestion']['decision']);
        self::assertSame(1, $boundary->prepare, 'Retry must reuse the original barrier');
        self::assertCount(2, $this->state()['document_revision']);
    }

    public static function collaborationUncertainDrainReasons(): array
    {
        return [['COLLABORATION_CONTROL_UNCONFIRMED'], ['COLLABORATION_HOLDER_CHANGED']];
    }

    public function testCollaborativeSuggestionFinalEditRefusalIsUnconfirmedAfterExternalApply(): void
    {
        [$revision, $created, $input, $boundary] = $this->collaborativeSuggestion('final-edit');
        $this->unconfirmedSuggestion($revision, $input, 'COMMENT_FIXTURE_EDIT_DENIED');
        self::assertSame(1, $boundary->apply);
        $state = $this->state();
        self::assertCount(1, $state['document_revision']);
        self::assertCount(1, $state['document_comment_operation']);
        self::assertSame('pending', $state['document_comment_thread'][0]['decision']);
        self::assertSame($input['request_id'], $state['document_comment_thread'][0]['accept_request_id']);
        self::assertSame(0, $boundary->release);
    }

    public function testCollaborativeSuggestionCommittedReleaseUncertaintyReplaysReceiptWithoutApplyingAgain(): void
    {
        [$revision, $created, $input, $boundary] = $this->collaborativeSuggestion('release');
        $this->unconfirmedSuggestion($revision, $input, 'COLLABORATION_RELEASE_UNCONFIRMED');
        $state = $this->state();
        self::assertCount(2, $state['document_revision']);
        self::assertCount(2, $state['document_comment_operation']);
        self::assertSame('accepted', $state['document_comment_thread'][0]['decision']);
        $op = array_values(array_filter($state['document_operation'], static fn($row) => $row['action'] === 'suggestion.accept'))[0];
        self::assertSame('completed', $op['phase']);
        self::assertNull($op['released_at']);
        $receipt = json_decode($state['document_comment_operation'][1]['result_json'], true, 64, JSON_THROW_ON_ERROR);
        $boundary->failure = '';
        $accepted = $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame($receipt, $accepted);
        foreach (['document_revision', 'document_comment_thread', 'document_comment_message', 'document_comment_operation', 'document_change'] as $table) {
            self::assertSame($state[$table], $this->state()[$table], 'Release retry cannot apply or decide a second time');
        }
        self::assertSame(1, $boundary->prepare);
        self::assertSame(1, $boundary->drain);
        self::assertSame(1, $boundary->apply);
        self::assertSame(1, $boundary->consume);
        self::assertSame(2, $boundary->release);
    }

    public function testCollaborativeSuggestionConfirmedPreMutationCancellationKeepsOriginalRejection(): void
    {
        [$revision, $created, $input, $boundary] = $this->collaborativeSuggestion('cancel');
        try {
            $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
            self::fail('Expected confirmed refusal');
        } catch (BusinessException $error) {
            self::assertSame('SUGGESTION_BASELINE_CONFLICT', $error->errorCode);
        }
        $state = $this->state();
        self::assertCount(1, $state['document_revision']);
        self::assertCount(1, $state['document_comment_operation']);
        self::assertSame('pending', $state['document_comment_thread'][0]['decision']);
        self::assertNull($state['document_comment_thread'][0]['accept_request_id']);
        $op = array_values(array_filter($state['document_operation'], static fn($row) => $row['action'] === 'suggestion.accept'))[0];
        self::assertSame('completed', $op['phase']);
        self::assertNotNull($op['released_at']);
        self::assertTrue(json_decode($op['result_json'], true, 64, JSON_THROW_ON_ERROR)['cancelled']);
        self::assertSame(1, $boundary->release);
        $before = $this->state();
        $this->owner->denied['11:edit'] = true;
        $this->rejected('COMMENT_FIXTURE_EDIT_DENIED', fn() => $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], array_replace($input, ['request_id' => 'never-prepared'])));
        self::assertSame($before, $this->state(), 'Before preparation refusal stays deterministic and writes nothing');
    }

    public function testCollaborativeSuggestionCancelledReleaseUncertaintyRequiresOriginalRequestRecovery(): void
    {
        [$revision, $created, $input, $boundary] = $this->collaborativeSuggestion('cancel-release');
        $this->unconfirmedSuggestion($revision, $input, 'COLLABORATION_RELEASE_UNCONFIRMED');
        $state = $this->state();
        self::assertCount(1, $state['document_revision']);
        self::assertSame('pending', $state['document_comment_thread'][0]['decision']);
        $op = array_values(array_filter($state['document_operation'], static fn($row) => $row['action'] === 'suggestion.accept'))[0];
        self::assertSame('completed', $op['phase']);
        self::assertNull($op['released_at']);
        self::assertTrue(json_decode($op['result_json'], true, 64, JSON_THROW_ON_ERROR)['cancelled']);
        $boundary->failure = '';
        try {
            $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
            self::fail('Confirmed cancellation must return its original rejection');
        } catch (BusinessException $error) {
            self::assertSame('SUGGESTION_BASELINE_CONFLICT', $error->errorCode);
        }
        self::assertSame(1, $boundary->prepare);
        self::assertSame(1, $boundary->apply);
        self::assertSame(2, $boundary->release);
        self::assertCount(1, $this->state()['document_revision']);
        self::assertCount(1, $this->state()['document_comment_operation']);
    }

    private function suggestion(array $revision, string $request = 'suggest-create'): array
    {
        return $this->comments->createSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'],
            $this->input($this->source($revision), $request) + ['candidate_content' => self::projection('建议正文😀')]);
    }

    public function testSuggestionAcceptCreatesNewFixedRevisionAndKeepsOriginalCandidateAndReceipt(): void
    {
        $revision = $this->revision();
        $source = $this->source($revision);
        $before = $this->state();
        $created = $this->suggestion($revision);
        foreach (['document', 'document_state', 'document_revision', 'document_change'] as $table) {
            self::assertSame($before[$table], $this->state()[$table], 'Creating a suggestion never applies or approves its text');
        }
        self::assertSame('pending', $created['suggestion']['decision']);
        self::assertNull($created['suggestion']['result_revision_id']);
        self::assertSame('open', $created['status']);
        $candidate = RichTextProjection::canonical(self::projection('建议正文😀'));
        self::assertSame($candidate, $created['suggestion']['candidate_content']);
        $input = $this->transition($created, 'suggest-accept') + ['expected_state_seq' => 1];
        $accepted = $this->comments->acceptSuggestion($this->sessions->actor(22), $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame('accepted', $accepted['suggestion']['decision']);
        self::assertSame('open', $accepted['status'], 'Decision does not resolve discussion');
        self::assertSame(22, $accepted['actor_member_id']);
        self::assertSame(2, $accepted['result_state_seq']);
        self::assertNotSame($revision['revision_id'], $accepted['result_revision_id']);
        self::assertSame($candidate, $accepted['suggestion']['candidate_content']);
        self::assertSame($created['suggestion']['candidate_sha256'], $accepted['suggestion']['candidate_sha256']);
        self::assertSame($source, $this->source($revision), 'Original fixed version and anchor remain immutable');
        $snapshot = $this->queries->versionSnapshot($this->sessions->actor(), $revision['document_id'], $accepted['result_revision_id']);
        self::assertSame($candidate, $snapshot->structuredContent);
        self::assertSame(2, $snapshot->sourceStateSeq);
        self::assertCount(2, $this->state()['document_revision']);
        $persisted = $this->state();
        $auditCount = count($this->audit->events);
        self::assertSame($accepted, $this->comments->acceptSuggestion($this->sessions->actor(22), $revision['document_id'], $revision['revision_id'], $input));
        self::assertSame($persisted, $this->state());
        self::assertCount($auditCount, $this->audit->events);
        self::assertSame($created, $this->suggestion($revision), 'Creation replay retains the original pending receipt');
        $this->rejected('DOCUMENT_COMMENT_REQUEST_CONFLICT', fn() => $this->comments->createSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'],
            $this->input($source, 'suggest-create') + ['candidate_content' => self::projection('Changed request candidate')]));
        $this->owner->denied['22:edit'] = true;
        $this->rejected('COMMENT_FIXTURE_EDIT_DENIED', fn() => $this->comments->acceptSuggestion($this->sessions->actor(22), $revision['document_id'], $revision['revision_id'], $input));
        unset($this->owner->denied['22:edit']);
        $this->rejected('DOCUMENT_COMMENT_REQUEST_CONFLICT', fn() => $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input));
        $this->rejected('DOCUMENT_COMMENT_REQUEST_CONFLICT', fn() => $this->comments->acceptSuggestion($this->sessions->actor(22), $revision['document_id'], $revision['revision_id'], array_replace($input, ['expected_state_seq' => 2])));
        $this->rejected('DOCUMENT_SUGGESTION_ALREADY_DECIDED', fn() => $this->comments->rejectSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $this->transition($accepted, 'reject-accepted')));
        $changes = $this->queries->changes($this->sessions->actor(), $revision['document_id'], 1);
        self::assertCount(1, $changes['items']);
        $change = $changes['items'][0];
        self::assertSame('suggestion.accept', $change['kind']);
        self::assertSame(22, $change['actor_member_id']);
        self::assertSame('suggest-accept', $change['operation_id']);
        self::assertSame($source['text'], $change['before_text']);
        self::assertSame("建议正文😀\nend", $change['after_text']);
        self::assertSame($created['suggestion']['candidate_sha256'], $change['to_projection_sha256']);
        self::assertSame($change['change_id'], $changes['next_before_change_id']);
        $previous = $this->queries->changes($this->sessions->actor(), $revision['document_id'], 1, $changes['next_before_change_id']);
        self::assertSame('solo.save', $previous['items'][0]['kind']);
        self::assertNull($previous['next_before_change_id']);
    }

    public function testSuggestionDiscussionAndRejectionAreIndependentAndNeverApplyCandidate(): void
    {
        $revision = $this->revision();
        $created = $this->suggestion($revision);
        $actor = $this->sessions->actor(22);
        $resolved = $this->comments->resolve($actor, $revision['document_id'], $revision['revision_id'], $this->transition($created, 'suggest-resolve'));
        self::assertSame('pending', $resolved['suggestion']['decision']);
        $before = $this->state();
        $input = $this->transition($resolved, 'suggest-reject');
        $rejected = $this->comments->rejectSuggestion($actor, $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame('resolved', $rejected['status']);
        self::assertSame('rejected', $rejected['suggestion']['decision']);
        self::assertSame($created['suggestion']['candidate_content'], $rejected['suggestion']['candidate_content']);
        self::assertNull($rejected['suggestion']['result_revision_id']);
        foreach (['document', 'document_state', 'document_revision', 'document_change'] as $table) self::assertSame($before[$table], $this->state()[$table]);
        $persisted = $this->state();
        self::assertSame($rejected, $this->comments->rejectSuggestion($actor, $revision['document_id'], $revision['revision_id'], $input));
        self::assertSame($persisted, $this->state());
        $this->rejected('DOCUMENT_SUGGESTION_ALREADY_DECIDED', fn() => $this->comments->acceptSuggestion($actor, $revision['document_id'], $revision['revision_id'], $this->transition($rejected, 'accept-rejected') + ['expected_state_seq' => 1]));
        $reopened = $this->comments->reopen($actor, $revision['document_id'], $revision['revision_id'], $this->transition($rejected, 'reopen-rejected'));
        self::assertSame('rejected', $reopened['suggestion']['decision']);
        $detail = $this->comments->thread($actor, $revision['document_id'], $revision['revision_id'], $created['thread_id']);
        self::assertSame(['suggest', 'resolve', 'reject', 'reopen'], array_column($detail['messages'], 'action'));
        self::assertSame($reopened['suggestion'], $detail['thread']['suggestion']);
    }

    public function testSuggestionAcceptRejectsChangedDraftEvenWithCurrentSequence(): void
    {
        $revision = $this->revision();
        $created = $this->suggestion($revision);
        $actor = $this->sessions->actor();
        $this->commands->saveSolo($actor, $revision['document_id'], 1, self::projection('New independent draft'), 'independent-save');
        $this->rejected('DOCUMENT_STATE_SEQ_CONFLICT', fn() => $this->comments->acceptSuggestion($actor, $revision['document_id'], $revision['revision_id'], $this->transition($created, 'stale-sequence') + ['expected_state_seq' => 1]));
        $this->rejected('DOCUMENT_SUGGESTION_BASE_CHANGED', fn() => $this->comments->acceptSuggestion($actor, $revision['document_id'], $revision['revision_id'], $this->transition($created, 'changed-baseline') + ['expected_state_seq' => 2]));
        self::assertSame('pending', $this->comments->thread($actor, $revision['document_id'], $revision['revision_id'], $created['thread_id'])['thread']['suggestion']['decision']);
    }

    #[DataProvider('suggestionCommands')]
    public function testSuggestionCommandsEnforceCurrentEditTenantAndSession(string $action): void
    {
        $revision = $this->revision();
        $created = $this->suggestion($revision);
        $actor = $this->sessions->actor(22);
        $input = $action === 'createSuggestion'
            ? $this->input($this->source($revision), 'other-create') + ['candidate_content' => self::projection('Other candidate')]
            : $this->transition($created, 'other-decision') + ($action === 'acceptSuggestion' ? ['expected_state_seq' => 1] : []);
        $call = fn(TenantContext $current) => $this->comments->$action($current, $revision['document_id'], $revision['revision_id'], $input);
        $this->owner->denied['22:edit'] = true;
        $this->rejected('COMMENT_FIXTURE_EDIT_DENIED', fn() => $call($actor));
        unset($this->owner->denied['22:edit']);
        $this->rejected('DOCUMENT_NOT_FOUND', fn() => $call($this->sessions->actor(33)));
        $this->sessions->revoked[22] = true;
        $this->rejected('DOCUMENT_SESSION_DENIED', fn() => $call($actor));
        unset($this->sessions->revoked[22]);
        ++$this->sessions->revision;
        $this->rejected('DOCUMENT_AUTHORIZATION_STALE', fn() => $call($actor));
    }

    public static function suggestionCommands(): array
    {
        return [['createSuggestion'], ['acceptSuggestion'], ['rejectSuggestion']];
    }

    public function testSuggestionAcceptStorageFailureRollsBackDraftVersionDecisionAndChangeThenRetries(): void
    {
        $revision = $this->revision();
        $created = $this->suggestion($revision);
        $input = $this->transition($created, 'retry-accept') + ['expected_state_seq' => 1];
        $before = $this->state();
        Db::execute("CREATE TRIGGER suggestion_receipt_failure BEFORE INSERT ON pa_document_comment_operation WHEN NEW.action = 'accept' BEGIN SELECT RAISE(ABORT, 'SUGGESTION_STORAGE_FAILURE'); END");
        try {
            $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
            self::fail('Storage error was hidden');
        } catch (think\db\exception\PDOException $error) {
            self::assertStringContainsString('SUGGESTION_STORAGE_FAILURE', $error->getMessage());
        }
        self::assertSame($before, $this->state());
        self::assertFalse(Db::connect()->getPdo()->inTransaction());
        Db::execute('DROP TRIGGER suggestion_receipt_failure');
        $accepted = $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame('accepted', $accepted['suggestion']['decision']);
        self::assertCount(2, $this->state()['document_revision']);
        self::assertCount(2, $this->state()['document_change']);
    }

    public function testSuggestionChangeSourceReadUsesCurrentDocumentAccess(): void
    {
        $revision = $this->revision();
        $this->rejected('DOCUMENT_NOT_FOUND', fn() => $this->queries->changes($this->sessions->actor(33), $revision['document_id']));
        $this->owner->denied['22:view'] = true;
        $this->rejected('COMMENT_FIXTURE_VIEW_DENIED', fn() => $this->queries->changes($this->sessions->actor(22), $revision['document_id']));
        $this->rejected('DOCUMENT_CHANGE_PAGE_INVALID', fn() => $this->queries->changes($this->sessions->actor(), $revision['document_id'], 101));
    }

    public function testSuggestionCanFillEmptyFixedRevisionWithoutWeakeningCommentAnchor(): void
    {
        $actor = $this->sessions->actor();
        $owner = $this->owner->add(1, 'empty-note');
        $document = $this->commands->create($actor, $owner, 'rich_text', 'empty-create');
        $empty = json_decode(RichTextProjection::empty()->json, true, 64, JSON_THROW_ON_ERROR);
        $saved = $this->commands->saveSolo($actor, $document['document_id'], 0, $empty, 'empty-save');
        $revision = $this->authorization->run($actor, fn(TenantContext $current) =>
            $this->commands->freeze($current, $document['document_id'], $saved['state_seq'], 'Empty fixed baseline', 'empty-freeze'));
        $source = $this->source($revision);
        self::assertSame('', $source['text']);
        $anchor = array_replace($this->input($source, 'empty-suggest'), ['end_offset' => 0, 'quote' => '']);
        $this->rejected('DOCUMENT_COMMENT_INPUT_INVALID', fn() => $this->comments->createThread($actor, $revision['document_id'], $revision['revision_id'], $anchor));
        $created = $this->comments->createSuggestion($actor, $revision['document_id'], $revision['revision_id'], $anchor + ['candidate_content' => self::projection('First draft')]);
        $accepted = $this->comments->acceptSuggestion($actor, $revision['document_id'], $revision['revision_id'], $this->transition($created, 'empty-accept') + ['expected_state_seq' => 1]);
        self::assertSame('accepted', $accepted['suggestion']['decision']);
        self::assertSame('', $this->source($revision)['text']);
        self::assertSame(RichTextProjection::canonical(self::projection('First draft')),
            $this->queries->versionSnapshot($actor, $revision['document_id'], $accepted['result_revision_id'])->structuredContent);
        $nonempty = $this->revision();
        $invalid = array_replace($this->input($this->source($nonempty), 'nonempty-zero-anchor'), ['end_offset' => 0, 'quote' => '']);
        $this->rejected('DOCUMENT_COMMENT_RANGE_INVALID', fn() => $this->comments->createSuggestion($actor, $nonempty['document_id'], $nonempty['revision_id'], $invalid + ['candidate_content' => self::projection('Invalid anchor')]));
    }

    public function testSuggestionFinalEditRecheckRollsBackAppliedStateAndLeavesOriginalAcceptRetryable(): void
    {
        $revision = $this->revision();
        $created = $this->suggestion($revision);
        $input = $this->transition($created, 'final-edit-accept') + ['expected_state_seq' => 1];
        $afterWriteReached = false;
        $this->fence->onLock = function () use ($revision, &$afterWriteReached): void {
            if ((int) Db::name('document')->where('id', $revision['document_id'])->value('state_seq') === 2) {
                $afterWriteReached = true;
                $this->owner->denied['11:edit'] = true;
            }
        };
        $this->rejected('COMMENT_FIXTURE_EDIT_DENIED', fn() => $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input));
        self::assertTrue($afterWriteReached, 'Revocation must happen after real draft/version/decision writes');
        $this->fence->onLock = null;
        unset($this->owner->denied['11:edit']);
        $accepted = $this->comments->acceptSuggestion($this->sessions->actor(), $revision['document_id'], $revision['revision_id'], $input);
        self::assertSame('accepted', $accepted['suggestion']['decision']);
        self::assertCount(2, $this->state()['document_revision']);
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
            return $this->authorization->run($actor, fn() => (new DocumentRevisionCommentController($this->app, $this->comments))->createThread()->getData()['data']);
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

    public function testHistoricalDetailAndCapabilitiesRejectUncommittedOrCorruptVersions(): void
    {
        $revision = $this->revision();
        $actor = $this->sessions->actor();
        $reads = [
            fn() => $this->queries->detail($actor, $revision['document_id'], $revision['revision_id']),
            fn() => $this->queries->capabilities($actor, $revision['document_id'], 'view', $revision['revision_id']),
        ];
        foreach ($reads as $read) {
            try {
                Db::transaction($read);
                self::fail('Historical reads must reject a caller-owned transaction');
            } catch (BusinessException $error) {
                self::assertSame(409, $error->httpStatus);
                self::assertSame('DOCUMENT_VERSION_NOT_COMMITTED', $error->errorCode);
            }
        }
        Db::name('document_revision')->where('id', $revision['revision_id'])->update(['snapshot_sha256' => str_repeat('0', 64)]);
        foreach ($reads as $read) {
            try {
                $read();
                self::fail('Historical reads must reject a corrupt version digest');
            } catch (BusinessException $error) {
                self::assertSame(409, $error->httpStatus);
                self::assertSame('DOCUMENT_VERSION_DIGEST_INVALID', $error->errorCode);
            }
        }
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
