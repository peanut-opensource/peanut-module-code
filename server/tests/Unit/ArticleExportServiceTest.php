<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\PermissionDecision;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\model\TenantOwnedModel;
use app\common\services\ProductAssetReferenceService;
use app\common\services\RichTextResourceService;
use app\common\services\XlsxExportService;
use app\common\tenancy\MultiTenantDataScopePolicy;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Article\Service\ArticleAdministrationService;
use PeanutAdmin\Modules\Article\Service\ArticleBodyService;
use PeanutAdmin\Modules\Article\Service\ArticleCategoryAdministrationService;
use PeanutAdmin\Modules\Article\Service\ArticleDocumentOwnerPolicy;
use PeanutAdmin\Modules\Article\Validation\ArticleValidate;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Model;

/** Real service/ORM/scope unit fixtures. SQLite memory + mocked authorization/file delivery, NOT deployment acceptance. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ArticleExportServiceTest extends TestCase
{
    private \PDO $pdo;
    private ExecutionContextStore $contexts;
    private ArticleAdministrationService $articles;
    private ArticleCategoryAdministrationService $categories;
    private array $exports = [];
    private string $deniedPermission = '';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        require_once dirname(__DIR__, 2) . '/app/modules/peanut/document/src/Contract/DocumentOwnerPolicy.php';
        require_once dirname(__DIR__, 2) . '/app/modules/peanut/document/src/Contract/DocumentPublicationOwnerPolicy.php';
        require_once dirname(__DIR__, 2) . '/app/modules/peanut/document/src/Contract/DocumentCommands.php';
        require_once dirname(__DIR__, 2) . '/app/modules/peanut/document/src/Contract/DocumentQueries.php';
        require_once dirname(__DIR__, 2) . '/app/modules/peanut/document/src/Contract/DocumentOwnerReference.php';
        require_once dirname(__DIR__, 2) . '/app/modules/peanut/document/src/Contract/DocumentOwnerState.php';
        $app = new App(sys_get_temp_dir() . '/article-export-unit-' . bin2hex(random_bytes(6)));
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($this->contexts);
        $app->instance(CurrentExecutionContext::class, $current);
        $scope = new MultiTenantDataScopePolicy($current);
        Model::maker(static function (Model $model) use ($scope): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($scope);
            }
        });
        $authorization = $this->createStub(AdminAuthorizationQuery::class);
        $authorization->method('decide')->willReturnCallback(function ($context, $actor, string $permission): PermissionDecision {
            return $permission === $this->deniedPermission
                ? PermissionDecision::deny($permission, 'UNIT_DENIED')
                : PermissionDecision::allow($permission);
        });
        $authorization->method('principal')->willReturnCallback(static fn(TenantContext $context) => \app\common\dto\authorization\AdminPrincipal::fromArray([
            'id' => $context->memberId, 'tenant_id' => $context->tenantId,
            'account_id' => $context->accountId, 'authorization_revision' => $context->authorizationRevision,
        ]));
        $files = $this->createStub(FileReferences::class);
        $files->method('getFileUrl')->willReturnCallback(static fn(string $value): string => $value);
        $files->method('setTenantFileUrl')->willReturnCallback(static fn($context, string $value): string => $value);
        $xlsx = $this->createStub(XlsxExportService::class);
        $xlsx->method('create')->willReturnCallback(function (string $name, array $headings, array $rows): array {
            $this->exports[] = ['headings' => $headings, 'rows' => $rows];
            return ['url' => '/unit-only-not-a-download', 'original_name' => $name . '.xlsx'];
        });
        $assets = new ProductAssetReferenceService($files, 'https://unit.invalid');
        $owner = new ArticleDocumentOwnerPolicy($authorization, $current);
        $documents = $this->createStub(DocumentCommands::class);
        $documents->method('runOwnerLifecycle')->willReturnCallback(static function (TenantContext $context, DocumentOwnerReference $reference, string $action, callable $commit) use ($owner): mixed {
            $state = $owner->resolveOwner($context, $reference, true);
            $owner->authorize($context, $state->archived === ($action === 'archive') ? $action : $action . '.prepare', $state);
            return \think\facade\Db::transaction($commit);
        });
        $queries = $this->createStub(DocumentQueries::class);
        $this->articles = new ArticleAdministrationService(
            $current,
            $authorization,
            $assets,
            new RichTextResourceService($files),
            $xlsx,
            $documents,
            new ArticleBodyService($documents, $queries, $assets, $app),
            $queries,
        );
        $this->categories = new ArticleCategoryAdministrationService($current, $authorization, $xlsx);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_article_cate (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, sort INTEGER DEFAULT 0, is_show INTEGER DEFAULT 1, create_time INTEGER DEFAULT 10, update_time INTEGER DEFAULT 10, delete_time INTEGER DEFAULT NULL);
CREATE TABLE pa_article (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, cid INTEGER NOT NULL, title TEXT, "desc" TEXT DEFAULT '', abstract TEXT DEFAULT '', image TEXT DEFAULT '', author TEXT DEFAULT '', content TEXT DEFAULT '', body_document_id INTEGER, body_revision_id INTEGER, body_source_state_seq INTEGER, body_snapshot_sha256 TEXT, click_virtual INTEGER DEFAULT 0, click_actual INTEGER DEFAULT 0, is_show INTEGER DEFAULT 1, sort INTEGER DEFAULT 0, create_time INTEGER DEFAULT 10, update_time INTEGER DEFAULT 10, delete_time INTEGER DEFAULT NULL);
INSERT INTO pa_article_cate (id,tenant_id,name,delete_time) VALUES (1,101,'Alpha',NULL),(2,101,'Alpha trash',20),(3,202,'Beta',NULL);
INSERT INTO pa_article (id,tenant_id,cid,title,image,delete_time) VALUES (1,101,1,'keep-alpha','unit-file-reference',NULL),(2,101,1,'trashed-alpha','unit-file-reference',20),(3,101,2,'trashed-category-alpha','unit-file-reference',20),(4,202,3,'keep-beta','beta-reference',NULL);
SQL);
    }

    private function context(int $tenantId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $tenantId + 2000,
            'article-export-unit-' . $tenantId,
            $tenantId,
            $tenantId + 1000,
            $tenantId + 2000,
            'admin-web',
            new \DateTimeImmutable('2031-01-01T00:00:00Z'),
            1,
            1,
        ), 'article-export-unit-request-' . $tenantId);
    }

    private function runAs(int $tenantId, callable $operation): mixed
    {
        $context = $this->context($tenantId);
        return $this->contexts->run(new AdminExecutionContext($context, 'article.export.unit', [
            'id' => $context->memberId, 'tenant_id' => $tenantId,
            'account_id' => $context->accountId, 'authorization_revision' => 1,
        ]), fn() => $operation($context));
    }

    private function exportedRecords(): array
    {
        $export = $this->exports[array_key_last($this->exports)];
        return array_map(static fn(array $row): array => array_combine($export['headings'], $row), $export['rows']);
    }

    public function testListMetadataAndExportUseTheSameScopedRowsFieldsAndFormatting(): void
    {
        $this->runAs(101, function (TenantContext $context): void {
            $params = ['title' => 'keep', 'page_size' => 25];
            $page = $this->articles->lists($context, $params);
            $info = $this->articles->lists($context, $params + ['export' => 1]);
            self::assertSame(1, $info['count']);
            self::assertSame([1], array_column($page->items, 'id'));
            $this->articles->lists($context, $params + ['export' => 2]);
            self::assertEquals($page->items, $this->exportedRecords());
            self::assertArrayNotHasKey('tenant_id', $this->exportedRecords()[0]);
            self::assertSame('Alpha', $this->exportedRecords()[0]['cate_name']);
        });
        $this->runAs(202, function (TenantContext $context): void {
            $this->articles->lists($context, ['export' => 2]);
            self::assertSame([4], array_column($this->exportedRecords(), 'id'));
        });
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testTimeFiltersAndExplicitPageRangesDoNotWidenTheList(): void
    {
        $this->pdo->exec("INSERT INTO pa_article (id,tenant_id,cid,title,create_time) VALUES (5,101,1,'later-alpha',100)");
        $this->runAs(101, function (TenantContext $context): void {
            $params = ['start' => 50, 'end' => 150, 'field' => 'create_time', 'order_by' => 'asc'];
            $page = $this->articles->lists($context, $params);
            self::assertSame([5], array_column($page->items, 'id'));
            self::assertSame(1, $this->articles->lists($context, $params + ['export' => 1])['count']);
            $this->articles->lists($context, $params + ['export' => 2, 'page_type' => 1, 'page_size' => 1, 'page_start' => 1, 'page_end' => 1]);
            self::assertEquals($page->items, $this->exportedRecords());
            foreach ([['page_type' => 'invalid'], ['page_start' => 2, 'page_end' => 1, 'page_type' => 1]] as $range) {
                $before = count($this->exports);
                try {
                    $this->articles->lists($context, ['export' => 2] + $range);
                    self::fail('Invalid direct-service export range was accepted');
                } catch (BusinessException $exception) {
                    self::assertSame('ARTICLE_EXPORT_RANGE_INVALID', $exception->errorCode);
                    self::assertCount($before, $this->exports);
                }
            }
        });
    }

    public function testExplicitUnpagedExportUsesTheSameBoundAsTheList(): void
    {
        $this->runAs(101, function (TenantContext $context): void {
            foreach ([$this->articles, $this->categories] as $service) {
                $params = ['page_type' => 0, 'page_size' => 25000];
                $page = $service->lists($context, $params);
                $info = $service->lists($context, $params + ['export' => 1]);
                self::assertSame($page->total, $info['count']);
                self::assertSame(25000, $info['page_size']);
                $service->lists($context, $params + ['export' => 2]);
                self::assertEquals($page->items, $this->exportedRecords());
            }
        });
    }

    public function testCategoryExportPreservesActiveAndRecycledAssociationCounts(): void
    {
        $this->runAs(101, function (TenantContext $context): void {
            $page = $this->categories->lists($context, []);
            self::assertSame(1, $page->items[0]['article_count']);
            self::assertSame(1, $this->categories->lists($context, ['export' => 1])['count']);
            $this->categories->lists($context, ['export' => 2]);
            self::assertEquals($page->items, $this->exportedRecords());
            $trash = $this->categories->recycleLists($context, []);
            $this->categories->recycleLists($context, ['export' => 2]);
            self::assertEquals($trash->items, $this->exportedRecords());
            self::assertSame([2], array_column($this->exportedRecords(), 'id'));
            self::assertSame(1, $this->exportedRecords()[0]['article_count']);
        });
    }

    public function testRecycledArticlesCanFilterADeletedCategoryWithoutAnActiveCategoryLookup(): void
    {
        $this->runAs(101, function (TenantContext $context): void {
            self::assertTrue((new ArticleValidate())->scene('recycle')->check(['cid' => 2, 'export' => 2]));
            self::assertSame(1, $this->articles->recycleLists($context, ['cid' => 2, 'export' => 1])['count']);
            $this->articles->recycleLists($context, ['cid' => 2, 'export' => 2]);
            self::assertSame([3], array_column($this->exportedRecords(), 'id'));
            self::assertSame('Alpha trash', $this->exportedRecords()[0]['cate_name']);
        });
    }

    public function testTrashPermissionAndMismatchedTenantAreRejectedBeforeExport(): void
    {
        $this->deniedPermission = 'peanut.article.recycle.list';
        $this->runAs(101, function (TenantContext $context): void {
            foreach ([fn() => $this->articles->recycleLists($context, ['export' => 2]),
                fn() => $this->articles->lists($this->context(202), ['export' => 2])] as $attempt) {
                try {
                    $attempt();
                    self::fail('Unauthorized export was accepted');
                } catch (BusinessException $exception) {
                    self::assertSame('ARTICLE_ADMIN_PERMISSION_DENIED', $exception->errorCode);
                }
            }
            self::assertSame([], $this->exports);
        });
    }

    public function testSoftDeleteRestoreAndCategoryConflictArePreservedThroughServiceCalls(): void
    {
        $this->runAs(101, function (TenantContext $context): void {
            self::assertTrue($this->articles->delete($context, 1));
            self::assertTrue($this->articles->delete($context, 1));
            self::assertSame(0, $this->articles->lists($context, ['export' => 1])['count']);
            self::assertSame([], $this->articles->detail($context, 1));
            self::assertSame(0, $this->categories->lists($context, [])->items[0]['article_count']);
            self::assertTrue($this->categories->delete($context, 1));
            $blocked = $this->articles->restore($context, [1]);
            self::assertSame('ARTICLE_CATEGORY_UNAVAILABLE', $blocked['failed'][0]['code']);
            self::assertSame([1], $this->categories->restore($context, [1])['restored']);
            self::assertSame([1], $this->articles->restore($context, [1])['restored']);
            self::assertSame([1], $this->articles->restore($context, [1])['already_active']);
            self::assertSame('unit-file-reference', $this->articles->detail($context, 1)['image']);
            try {
                $this->categories->delete($context, 1);
                self::fail('Referenced category was deleted');
            } catch (BusinessException $exception) {
                self::assertSame('ARTICLE_CATEGORY_IN_USE', $exception->errorCode);
            }
            try {
                $this->articles->delete($context, 4);
                self::fail('Cross-tenant article was deleted');
            } catch (BusinessException $exception) {
                self::assertSame('ARTICLE_NOT_FOUND', $exception->errorCode);
            }
        });
        self::assertNull($this->pdo->query('SELECT delete_time FROM pa_article WHERE id=4')->fetchColumn());
    }
}
