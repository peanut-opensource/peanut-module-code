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

require_once __DIR__ . '/ArticleHtmlMigrationFixture.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ArticleHtmlMigrationTest extends ArticleHtmlMigrationFixture
{
    public function testConfirmedMigrationPreservesHtmlAndReplaysAfterLaterEdits(): void
    {
        $input = $this->input();
        $raw = $this->pdo->query('SELECT content FROM pa_article WHERE id=1')->fetchColumn();
        $result = $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input)));
        self::assertSame('completed', $result['phase']);
        self::assertFalse($result['published']);
        self::assertSame($raw, $this->pdo->query('SELECT content FROM pa_article WHERE id=1')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT body_revision_id FROM pa_article WHERE id=1')->fetchColumn());
        $this->pdo->exec("UPDATE unit_document SET state_seq=7,content='later edit'");
        self::assertSame($result, $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input))));
        self::assertSame('later edit', $this->pdo->query('SELECT content FROM unit_document')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM unit_document')->fetchColumn());
        $changed = $input;
        $changed['structured_content']['content']['content'][0]['content'][0]['text'] = 'changed';
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($changed))), 'ARTICLE_HTML_REQUEST_CONFLICT');
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input)), 302), 'ARTICLE_HTML_ACTOR_DENIED');
        $input['request_id'] = 'another';
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input))), 'ARTICLE_HTML_MIGRATION_UNAVAILABLE');
    }

    public function testConfirmationProjectionAndSourceCasAreRequired(): void
    {
        $input = $this->input();
        foreach ([false, 'true', 1, null] as $confirmation) {
            $bad = $input;
            $bad['confirmed'] = $confirmation;
            $this->reject(fn() => new ArticleHtmlMigrationInput($bad), 'ARTICLE_HTML_INPUT_INVALID');
        }
        $bad = $input;
        $bad['structured_content']['content']['content'][] = ['type' => 'image', 'attrs' => ['fileKey' => 'file_' . str_repeat('a', 32), 'kind' => 'image']];
        $this->reject(fn() => new ArticleHtmlMigrationInput($bad), 'ARTICLE_HTML_MEDIA_UNSUPPORTED');
        $bad = $input;
        $bad['structured_content']['content']['content'][] = ['type' => 'iframe'];
        $this->reject(fn() => new ArticleHtmlMigrationInput($bad), 'RICH_TEXT_PROJECTION_INVALID');
        $bad = $input;
        $bad['source_sha256'] = str_repeat('0', 64);
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($bad))), 'ARTICLE_HTML_SOURCE_CHANGED');
        $this->pdo->exec("UPDATE pa_article SET title='concurrent metadata' WHERE id=1");
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input))), 'ARTICLE_HTML_SOURCE_CHANGED');
        $this->pdo->exec("UPDATE pa_article SET content='<p>concurrent source</p>' WHERE id=1");
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input))), 'ARTICLE_HTML_SOURCE_CHANGED');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM unit_document')->fetchColumn());
    }

    public function testPermissionTenantLifecycleAndOccupiedSlotFailClosed(): void
    {
        $input = $this->input();
        $this->denied = true;
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input))), 'ARTICLE_DOCUMENT_DENIED');
        $this->denied = false;
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlPreview($ctx, 2)), 'ARTICLE_NOT_FOUND');
        $this->pdo->exec('UPDATE pa_article SET is_show=1 WHERE id=1');
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlPreview($ctx, 1)), 'ARTICLE_HTML_MIGRATION_UNAVAILABLE');
        $this->pdo->exec('UPDATE pa_article SET is_show=0,delete_time=20 WHERE id=1');
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlPreview($ctx, 1)), 'ARTICLE_DOCUMENT_STATE_DENIED');
        $this->pdo->exec('UPDATE pa_article SET delete_time=NULL WHERE id=1');
        $this->pdo->exec('INSERT INTO unit_document(id,state_seq) VALUES(91,6)');
        $this->reject(fn() => $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input))), 'ARTICLE_HTML_DOCUMENT_EXISTS');
        self::assertSame(6, (int) $this->pdo->query('SELECT state_seq FROM unit_document')->fetchColumn());
    }

    public function testSaveFailureRollsBackCreateBindingAndReceipt(): void
    {
        $input = $this->input();
        $this->failSave = true;
        try {
            $this->runAs(fn($ctx) => $this->service->htmlMigrate($ctx, new ArticleHtmlMigrationInput($input)));
            self::fail('Expected synthetic failure');
        } catch (\RuntimeException $error) {
            self::assertSame('synthetic Document save failure', $error->getMessage());
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM unit_document')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_article_html_migration')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT body_document_id FROM pa_article WHERE id=1')->fetchColumn());
    }
}
