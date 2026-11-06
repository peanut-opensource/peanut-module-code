<?php

declare(strict_types=1);

namespace tests\Modules\Peanut\Article;

use PeanutAdmin\Modules\Document\Service\DocumentService;
use PeanutAdmin\Modules\Article\Contract\ArticleHtmlMigrationInput;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/ArticleHtmlMigrationFixture.php';

/** Real DocumentService, AuthorizationService, owner policy, Models and nested SQLite transactions. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ArticleHtmlNativeDocumentTest extends ArticleHtmlMigrationFixture
{
    private DocumentService $native;

    protected function nativeCommands($app, $authorization, $policy): DocumentService
    {
        require_once __DIR__ . '/ArticleNativeIdentityFence.php';
        class_alias(ArticleNativeIdentityFence::class, \PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationReadFence::class);
        $fence = new \PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationReadFence();
        $app->instance(\PeanutAdmin\Modules\Article\Service\ArticleDocumentOwnerPolicy::class, $policy);
        $owners = new \PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry($app, ['peanut.article:article' => $policy::class]);
        $modules = $this->createStub(\PeanutAdmin\Kernel\Module\ModuleRuntimeRepository::class);
        $modules->method('installation')->willReturnCallback(static fn($key) => new \PeanutAdmin\Kernel\Module\ModuleInstallationRecord($key, '1.0.0', 'active', 1, str_repeat('a', 64)));
        $modules->method('tenantModule')->willReturnCallback(static fn($tenant, $key) => new \PeanutAdmin\Kernel\Module\TenantModuleRecord($tenant, $key, 'enabled', null, null, 1));
        $sessions = $this->createStub(\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess::class);
        $sessions->method('revalidateSession')->willReturnCallback(fn() => (new \app\common\execution\CurrentExecutionContext($this->contexts))->tenantAdmin());
        $auth = new \PeanutAdmin\Modules\Document\Service\DocumentAuthorizationService($sessions, $authorization, $this->contexts, $modules, $owners, $fence);
        $references = $this->createStub(\PeanutAdmin\Modules\File\Contract\FileReferenceCommands::class);
        $references->method('replaceDocumentReferences')->willReturnCallback(static function ($ctx, $id, $keys): void {
            self::assertSame([], $keys, 'HTML migration must never infer File resources');
        });
        require_once __DIR__ . '/ArticleNativeAudit.php';
        class_alias(ArticleNativeAudit::class, \app\common\services\audit\AuditContractHost::class);
        $audit = new \app\common\services\audit\AuditContractHost();
        return $this->native = new DocumentService($auth, $references,
            $this->createStub(\PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots::class),
            $this->createStub(\PeanutAdmin\Modules\File\Contract\FileStorage::class), $audit,
            new \PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService(new \PeanutAdmin\Modules\ContentTools\Service\ContentToolRegistry()),
            $this->contexts, new \PeanutAdmin\Modules\Document\Service\DocumentAcceptedWriteRegistry($app, null), $owners, $fence, $modules);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_article_native_audit (id INTEGER PRIMARY KEY, event TEXT);
CREATE TABLE pa_document (id INTEGER PRIMARY KEY, tenant_id INTEGER, owner_module_key TEXT, owner_object_type TEXT, owner_object_id TEXT, slot TEXT, content_kind TEXT, body_type TEXT, schema_version TEXT, status TEXT, auth_revision INTEGER, state_seq INTEGER, collaboration_enabled INTEGER, lease_epoch INTEGER, created_by_member_id INTEGER, updated_by_member_id INTEGER, create_time INTEGER, update_time INTEGER, UNIQUE(tenant_id,owner_module_key,owner_object_type,owner_object_id,slot));
CREATE TABLE pa_document_state (id INTEGER PRIMARY KEY, tenant_id INTEGER, document_id INTEGER, yjs_state_binary BLOB, state_seq INTEGER, state_sha256 TEXT, projected_json JSON, projection_schema_version TEXT, persisted_at TEXT, last_store_id TEXT, UNIQUE(tenant_id,document_id));
CREATE TABLE pa_document_operation (id INTEGER PRIMARY KEY, tenant_id INTEGER, document_id INTEGER, operation_id TEXT, action TEXT, input_sha256 TEXT, result_json JSON, created_at TEXT, phase TEXT DEFAULT 'completed', UNIQUE(tenant_id,document_id,operation_id));
CREATE TABLE pa_document_file_ref (id INTEGER PRIMARY KEY, tenant_id INTEGER, document_id INTEGER, file_key TEXT, variant_key TEXT, usage TEXT, derived_state_seq INTEGER, updated_at TEXT);
SQL);
    }

    private function assertNativePaths(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([DocumentService::class => '/app/modules/peanut/document/src/Service/DocumentService.php',
            \PeanutAdmin\Modules\Article\Service\ArticleBodyService::class => '/app/modules/peanut/article/src/Service/ArticleBodyService.php',
            \PeanutAdmin\Modules\Document\Model\Document::class => '/app/modules/peanut/document/src/Model/Document.php',
            \PeanutAdmin\Modules\Document\Model\DocumentState::class => '/app/modules/peanut/document/src/Model/DocumentState.php'] as $class => $path) {
            self::assertSame(realpath($root . $path), (new \ReflectionClass($class))->getFileName());
        }
    }

    public function testNativeLifecycle(): void
    {
        $this->assertNativePaths();
        $input = $this->input();
        $result = $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input)));
        $editor = $this->runAs(fn($ctx) => $this->service->editor($ctx, 1));
        self::assertSame(\PeanutAdmin\Modules\RichText\Contract\RichTextProjection::canonical($input['structured_content']), $editor['structured_content']);
        self::assertSame(1, $editor['state_seq']);
        self::assertNull($editor['saved_binding']);
        self::assertSame('<p>旧正文 &amp; 原样</p>', $this->pdo->query('SELECT content FROM pa_article WHERE id=1')->fetchColumn());
        $later = $input['structured_content'];
        $later['content']['content'][0]['content'][0]['text'] = 'later native edit';
        $this->runAs(fn($ctx) => $this->native->saveSolo($ctx, $result['document_id'], 1, $later, 'later-edit'));
        self::assertSame($result, $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input))));
        $persisted = $this->runAs(fn($ctx) => $this->service->editor($ctx, 1));
        self::assertSame(\PeanutAdmin\Modules\RichText\Contract\RichTextProjection::canonical($later), $persisted['structured_content']);
        self::assertSame(2, $persisted['state_seq']);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_document_operation')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT is_show FROM pa_article WHERE id=1')->fetchColumn());
    }

    public function testNativeRollback(): void
    {
        $input = $this->input();
        // Fail after native create/save and Article binding, precisely at the final Article receipt.
        $this->pdo->exec("CREATE TRIGGER fail_html_receipt BEFORE INSERT ON pa_article_html_migration BEGIN SELECT RAISE(ABORT, 'synthetic receipt failure'); END");
        try {
            $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input)));
            self::fail('Expected injected receipt failure');
        } catch (\think\db\exception\PDOException $error) {
            self::assertStringContainsString('synthetic receipt failure', $error->getMessage());
        }
        foreach (['document', 'document_state', 'document_operation', 'article_html_migration', 'article_native_audit'] as $table) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_' . $table)->fetchColumn());
        }
        self::assertNull($this->pdo->query('SELECT body_document_id FROM pa_article WHERE id=1')->fetchColumn());
        $this->pdo->exec('DROP TRIGGER fail_html_receipt');
        $result = $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input)));
        self::assertSame('completed', $result['phase']);
        self::assertSame(\PeanutAdmin\Modules\RichText\Contract\RichTextProjection::canonical($input['structured_content']), $this->runAs(fn($ctx) => $this->service->editor($ctx, 1))['structured_content']);
    }
}
