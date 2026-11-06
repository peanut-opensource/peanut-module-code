<?php

declare(strict_types=1);

namespace tests\Modules\Peanut\Article;

use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\model\TenantOwnedModel;
use app\common\tenancy\MultiTenantDataScopePolicy;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Article\Contract\ArticleHtmlMigrationInput;
use PeanutAdmin\Modules\Article\Service\ArticleBodyService;
use PeanutAdmin\Modules\Article\Service\ArticleDocumentOwnerPolicy;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Real Article ORM/owner policy and SQLite memory; Document commands/auth are explicit substitutes. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
abstract class ArticleHtmlMigrationFixture extends TestCase
{
    protected \PDO $pdo;
    protected ExecutionContextStore $contexts;
    protected ArticleBodyService $service;
    protected bool $denied = false;
    protected bool $failSave = false;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        $loader = require $root . '/vendor/autoload.php';
        // Composer path packages are copied. Exercise this checkout, never the installed old Article copy.
        foreach (['Article' => 'article', 'Document' => 'document', 'RichText' => 'rich_text'] as $name => $path) {
            $loader->addPsr4('PeanutAdmin\\Modules\\' . $name . '\\', $root . '/app/modules/peanut/' . $path . '/src', true);
        }
        require_once $root . '/tests/Support/ThinkPhpTestConnection.php';
        require_once $root . '/vendor/topthink/framework/src/helper.php';
        $app = new \think\App(sys_get_temp_dir() . '/article-html-unit');
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($this->contexts);
        $app->instance(CurrentExecutionContext::class, $current);
        $scope = new MultiTenantDataScopePolicy($current);
        \think\Model::maker(static function (\think\Model $model) use ($scope): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($scope);
            }
        });
        $authorization = $this->createStub(\app\common\contract\authorization\AdminAuthorizationQuery::class);
        $authorization->method('principal')->willReturnCallback(static fn($ctx) => \app\common\dto\authorization\AdminPrincipal::fromArray([
            'id' => $ctx->memberId, 'account_id' => $ctx->accountId, 'tenant_id' => $ctx->tenantId, 'authorization_revision' => 1,
        ]));
        $authorization->method('decide')->willReturnCallback(fn($ctx, $actor, $permission) => $this->denied
            ? \app\common\dto\authorization\PermissionDecision::deny($permission, 'UNIT_DENIED')
            : \app\common\dto\authorization\PermissionDecision::allow($permission));
        $policy = new ArticleDocumentOwnerPolicy($authorization, $current);
        if ($this instanceof ArticleHtmlNativeDocumentTest) {
            $commands = $this->nativeCommands($app, $authorization, $policy);
        } else {
            $commands = $this->createStub(DocumentCommands::class);
            $commands->method('runOwnerVersionSave')->willReturnCallback(static fn($ctx, $owner, $commit) => \think\facade\Db::transaction(static function () use ($ctx, $owner, $commit, $policy) {
                $state = $policy->resolveOwner($ctx, $owner, true);
                $policy->authorize($ctx, 'version.create', $state);
                return $commit($ctx, $state);
            }));
            $commands->method('create')->willReturnCallback(function ($ctx, $owner) {
                $found = $this->pdo->query('SELECT id FROM unit_document')->fetchColumn();
                if ($found !== false) {
                    return ['document_id' => (int) $found, 'state_seq' => 0, 'created' => false];
                }
                $this->pdo->exec('INSERT INTO unit_document(id,state_seq) VALUES(91,0)');
                return ['document_id' => 91, 'state_seq' => 0, 'created' => true];
            });
            $commands->method('saveSolo')->willReturnCallback(function ($ctx, $id, $seq, $content) {
                if ($this->failSave) {
                    throw new \RuntimeException('synthetic Document save failure');
                }
                self::assertSame(0, $seq);
                $statement = $this->pdo->prepare('UPDATE unit_document SET state_seq=1, content=? WHERE id=?');
                $statement->execute([json_encode($content), $id]);
                return ['document_id' => $id, 'state_seq' => 1];
            });
        }
        $files = $this->createStub(\PeanutAdmin\Modules\File\Contract\FileReferences::class);
        $this->service = new ArticleBodyService($commands, $commands instanceof DocumentQueries ? $commands : $this->createStub(DocumentQueries::class), new \app\common\services\ProductAssetReferenceService($files, 'https://unit.invalid'), $app);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_article (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, cid INTEGER DEFAULT 1, title TEXT DEFAULT 'Legacy', "desc" TEXT DEFAULT '', abstract TEXT DEFAULT '', image TEXT DEFAULT '', author TEXT DEFAULT '', content TEXT, body_document_id INTEGER, body_revision_id INTEGER, body_source_state_seq INTEGER, body_snapshot_sha256 TEXT, click_virtual INTEGER DEFAULT 0, sort INTEGER DEFAULT 0, is_show INTEGER DEFAULT 0, create_time INTEGER DEFAULT 10, update_time INTEGER DEFAULT 10, delete_time INTEGER);
CREATE TABLE pa_article_html_migration (id INTEGER PRIMARY KEY, tenant_id INTEGER, article_id INTEGER, document_id INTEGER, request_id TEXT, input_sha256 TEXT, source_sha256 TEXT, actor_account_id INTEGER, actor_member_id INTEGER, result_json JSON, created_at TEXT, UNIQUE(tenant_id,article_id,request_id));
CREATE TABLE unit_document (id INTEGER PRIMARY KEY, state_seq INTEGER, content TEXT);
INSERT INTO pa_article(id,tenant_id,content) VALUES(1,101,'<p>旧正文 &amp; 原样</p>'),(2,202,'foreign');
SQL);
    }

    protected function runAs(callable $call, int $member = 301): mixed
    {
        $ctx = TenantContext::fromValidatedSession(new ValidatedTenantSession($member, 'html-unit-' . $member, 101, 201, $member, 'admin-web', new \DateTimeImmutable('2031-01-01T00:00:00Z'), 1, 1), 'html-unit');
        return $this->contexts->run(new AdminExecutionContext($ctx, 'article.html.unit', ['id' => $member, 'tenant_id' => 101, 'account_id' => 201, 'authorization_revision' => 1]), fn() => $call($ctx));
    }

    protected function input(): array
    {
        $source = $this->runAs(fn($ctx) => $this->service->htmlPreview($ctx, 1));
        return ['id' => 1, 'request_id' => 'html-007', 'confirmed' => true, 'owner_revision' => $source['owner_revision'], 'source_sha256' => $source['source_sha256'],
            'structured_content' => ['schemaVersion' => 'peanut.richtext/1', 'editorModel' => 'tiptap-prosemirror', 'annotations' => [], 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '旧正文 & 原样']]]]]]];
    }

    protected function reject(callable $call, string $code): void
    {
        try {
            $call();
            self::fail('Expected ' . $code);
        } catch (BusinessException $error) {
            self::assertSame($code, $error->errorCode);
        }
    }
}
