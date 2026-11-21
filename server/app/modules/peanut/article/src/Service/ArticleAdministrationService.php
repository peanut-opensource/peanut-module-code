<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\dto\authorization\AdminPrincipal;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\http\PageResult;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\common\services\ProductAssetReferenceService;
use app\common\services\RichTextResourceService;
use app\common\services\XlsxExportService;
use app\common\support\ExportPageInfo;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Article\Contract\ArticleAdministration;
use PeanutAdmin\Modules\Article\Contract\ArticleDraftImport;
use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleCate;
use PeanutAdmin\Modules\Article\Model\ArticleCollect;
use PeanutAdmin\Modules\Article\Validation\ArticleValidate;
use think\db\BaseQuery;
use think\facade\Db;

/** 资讯后台用例：权限、Tenant Scope、输入字段和软删除状态都在服务边界复核。 */
final class ArticleAdministrationService implements ArticleAdministration, ArticleDraftImport
{
    private const PAGE_SIZE_DEFAULT = 25;
    private const PAGE_SIZE_MAX = 25000;
    private const WRITE_FIELDS = [
        'cid', 'title', 'desc', 'abstract', 'image', 'author', 'content',
        'click_virtual', 'is_show', 'sort',
    ];

    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly AdminAuthorizationQuery $authorization,
        private readonly ProductAssetReferenceService $assets,
        private readonly RichTextResourceService $richText,
        private readonly XlsxExportService $xlsxExport,
        private readonly \PeanutAdmin\Modules\Document\Contract\DocumentCommands $documents,
        private readonly ArticleBodyService $bodies,
        private readonly \PeanutAdmin\Modules\Document\Contract\DocumentQueries $documentQueries,
    ) {}

    public function lists(TenantContext $context, array $params): PageResult|array
    {
        $this->assertPermission($context, 'peanut.article.list');
        return $this->articleLists($context, $params, false);
    }

    public function detail(TenantContext $context, int $id): array
    {
        $this->assertPermission($context, 'peanut.article.detail');
        return $this->findRow($id, false);
    }

    public function bodyDocument(TenantContext $context, int $id, string $requestId): array
    {
        $this->assertPermission($context, 'peanut.article.edit');
        return $this->bodies->ensure($context, $id, $requestId);
    }

    public function bodyHtmlPreview(TenantContext $context, int $id): array
    {
        $this->assertPermission($context, 'peanut.article.edit');
        return $this->bodies->htmlPreview($context, $id);
    }

    public function bodyHtmlMigrate(TenantContext $context, array $input): array
    {
        $this->assertPermission($context, 'peanut.article.edit');
        return $this->bodies->htmlMigrate($context, new \PeanutAdmin\Modules\Article\Contract\ArticleHtmlMigrationInput($input));
    }

    public function bodyEditor(TenantContext $context, int $id): array
    {
        $this->assertPermission($context, 'peanut.article.edit');
        return $this->bodies->editor($context, $id);
    }

    public function bodyTransport(TenantContext $context, int $id): array
    {
        $this->assertPermission($context, 'peanut.article.edit');
        return $this->bodies->transport($context, $id);
    }

    public function bodySave(TenantContext $context, array $input): array
    {
        $this->assertPermission($context, 'peanut.article.edit');
        return $this->bodies->save(
            $context,
            new \PeanutAdmin\Modules\Article\Contract\ArticleBodySaveInput($input),
            fn(int $cid) => $this->requireCategory($cid, true),
            fn(TenantContext $current, array $metadata): array => $this->articleWriteData($current, $metadata, false),
        );
    }

    public function bodySaveOperation(TenantContext $context, int $id, string $requestId): array
    {
        $this->assertPermission($context, 'peanut.article.edit');
        return $this->bodies->operation($context, $id, $requestId);
    }

    public function add(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, 'peanut.article.add');
        Db::transaction(fn(): int => $this->createArticle($context, $params));
        return true;
    }

    public function invalidDraftFields(TenantContext $context, array $row): array
    {
        $this->assertDraftImportPermission($context);
        $validator = (new ArticleValidate())->forTenant($context)->only(['title', 'cid'])->batch();
        $row['title'] = trim($row['title']);
        $invalid = $validator->check($row) ? [] : array_keys($validator->getError(true));
        if (strlen($this->draftBodyHtml($row['body'])) > 65535) {
            $invalid[] = 'body';
        }
        return $invalid;
    }

    public function importDraft(TenantContext $context, string $title, int $categoryId, string $body, string $rowKey): void
    {
        $this->assertDraftImportPermission($context);
        $title = trim($title);
        $content = $this->draftBodyHtml($body);
        if (!(new ArticleValidate())->only(['title'])->check(['title' => $title]) || strlen($content) > 65535) {
            throw BusinessException::invalid('ARTICLE_DRAFT_INVALID', '资讯草稿标题或正文无效');
        }
        $params = [
            'title' => $title,
            'cid' => $categoryId,
            'content' => $content,
            'is_show' => 0,
        ];
        $idempotency = app(IdempotentCommandExecutor::class);
        Db::transaction(function () use ($context, $params, $rowKey, $idempotency): void {
            $receipt = $idempotency->begin(IdempotencyCommand::tenant(
                $context,
                'article.draft-import',
                $rowKey,
                hash('sha256', json_encode($params, JSON_THROW_ON_ERROR)),
                new \DateTimeImmutable('+90 days'),
            ));
            if ($receipt->isReplayable()) {
                return;
            }
            if (!$receipt->isExecutionOwner()) {
                throw BusinessException::conflict('ARTICLE_DRAFT_IMPORT_IN_PROGRESS', '资讯草稿正在导入');
            }
            $id = $this->createArticle($context, $params);
            $idempotency->complete($receipt, new IdempotencyReceipt(201, ['id' => $id], 'article', (string) $id));
        });
    }

    private function draftBodyHtml(string $body): string
    {
        return nl2br(htmlspecialchars($body, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    private function createArticle(TenantContext $context, array $params): int
    {
        $this->requireCategory((int) $params['cid'], true);
        $article = new Article();
        if (!$article->save($this->articleWriteData($context, $params))) {
            throw new \LogicException('ARTICLE_CREATE_FAILED');
        }
        return (int) $article->id;
    }

    private function assertDraftImportPermission(TenantContext $context): void
    {
        if ($this->executionContext->systemExecution()->tenantId() !== $context->tenantId) {
            throw BusinessException::forbidden('ARTICLE_ADMIN_PERMISSION_DENIED', '无权管理资讯');
        }
        app(ModuleExecutionBoundary::class)->assertWorker('peanut.article');
        $principal = $this->authorization->principal($context);
        if (!$this->authorization->decide($context, $principal, 'peanut.article.add')->allowed) {
            throw BusinessException::forbidden('ARTICLE_ADMIN_PERMISSION_DENIED', '无权管理资讯');
        }
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, 'peanut.article.edit');
        return Db::transaction(function () use ($context, $params): bool {
            $this->requireCategory((int) $params['cid'], true);
            $article = Article::where([])->where('id', (int) $params['id'])->lock(true)->findOrEmpty();
            if ($article->isEmpty()) {
                throw BusinessException::notFound('ARTICLE_NOT_FOUND', '资讯不存在');
            }
            if ($article->body_document_id !== null) {
                throw BusinessException::conflict('ARTICLE_DOCUMENT_SAVE_REQUIRED', '已接入 Document 的资讯须使用正文保存入口');
            }
            $article->save($this->articleWriteData($context, $params));
            return true;
        });
    }

    public function delete(TenantContext $context, int $id): bool
    {
        $this->assertPermission($context, 'peanut.article.delete');
        return $this->documents->runOwnerLifecycle($context, new \PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference('peanut.article', 'article', (string) $id, 'body'), 'archive', function () use ($context, $id): bool {
            $this->assertPermission($context, 'peanut.article.delete');
            $article = Article::withTrashed()->where('id', $id)->lock(true)->findOrEmpty();
            if ($article->isEmpty()) {
                throw BusinessException::notFound('ARTICLE_NOT_FOUND', '资讯不存在');
            }
            if ($article->trashed()) {
                return true;
            }
            if (!$article->delete()) {
                throw new \LogicException('ARTICLE_SOFT_DELETE_FAILED');
            }
            // 封面和正文中的文件引用仍由文件模块保留；软删除不触发外部清理。
            return true;
        });
    }

    public function updateStatus(TenantContext $context, int $id, int $isShow): bool
    {
        $this->assertPermission($context, 'peanut.article.update-status');
        return Db::transaction(function () use ($id, $isShow): bool {
            $article = Article::where([])->where('id', $id)->lock(true)->findOrEmpty();
            if ($article->isEmpty()) {
                throw BusinessException::notFound('ARTICLE_NOT_FOUND', '资讯不存在');
            }
            if ((int) $article->is_show !== $isShow) {
                $article->save(['is_show' => $isShow]);
            }
            if ($isShow === 1 && $article->body_document_id !== null) {
                // Resolve only the formally saved binding. A failure rolls back publication in this transaction.
                $this->documentQueries->publicationBody(new \PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference('peanut.article', 'article', (string) $id, 'body'));
            }
            return true;
        });
    }

    public function recycleLists(TenantContext $context, array $params): PageResult|array
    {
        $this->assertPermission($context, 'peanut.article.recycle.list');
        return $this->articleLists($context, $params, true);
    }

    public function recycleDetail(TenantContext $context, int $id): array
    {
        $this->assertPermission($context, 'peanut.article.recycle.detail');
        return $this->findRow($id, true);
    }

    public function restore(TenantContext $context, array $ids): array
    {
        $this->assertPermission($context, 'peanut.article.restore');
        return $this->batch($ids, 'restored', function (int $id) use ($context): string {
            return $this->documents->runOwnerLifecycle($context, new \PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference('peanut.article', 'article', (string) $id, 'body'), 'restore', function () use ($context, $id): string {
                $this->assertPermission($context, 'peanut.article.restore');
                $article = Article::withTrashed()->where('id', $id)->lock(true)->findOrEmpty();
                if ($article->isEmpty()) {
                    throw BusinessException::notFound('ARTICLE_NOT_FOUND', '资讯不存在');
                }
                if (!$article->trashed()) {
                    return 'already_active';
                }
                $this->requireCategory((int) $article->cid, true);
                if (!$article->restore()) {
                    throw new \LogicException('ARTICLE_RESTORE_FAILED');
                }
                return 'restored';
            });
        });
    }

    public function forceDelete(TenantContext $context, array $ids): array
    {
        $this->assertPermission($context, 'peanut.article.force-delete');
        return $this->batch($ids, 'deleted', function (int $id) use ($context): string {
            return Db::transaction(function () use ($context, $id): string {
                $article = Article::withTrashed()->where('id', $id)->lock(true)->findOrEmpty();
                if ($article->isEmpty()) {
                    throw BusinessException::notFound('ARTICLE_NOT_FOUND', '资讯不存在');
                }
                if (!$article->trashed()) {
                    throw BusinessException::conflict('ARTICLE_FORCE_DELETE_REQUIRES_TRASHED', '仅回收站资讯可永久删除');
                }
                $this->documents->assertOwnerDeletionAllowed($context, new \PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference('peanut.article', 'article', (string) $id, 'body'));
                if (!ArticleCollect::withTrashed()->where('article_id', $id)
                    ->lock(true)->findOrEmpty()->isEmpty()
                ) {
                    throw BusinessException::conflict('ARTICLE_COLLECTION_REFERENCE_EXISTS', '资讯仍有收藏引用，不能永久删除');
                }
                if (!$article->force(true)->delete()) {
                    throw new \LogicException('ARTICLE_FORCE_DELETE_FAILED');
                }
                return 'deleted';
            });
        });
    }

    private function articleLists(TenantContext $context, array $params, bool $onlyTrashed): PageResult|array
    {
        $query = $onlyTrashed ? Article::onlyTrashed() : Article::where([]);
        $query->field(self::articleFields());
        if (isset($params['title']) && $params['title'] !== '') {
            $query->whereLike('title', '%' . trim((string) $params['title']) . '%');
        }
        if (isset($params['cid']) && $params['cid'] !== '') {
            $query->where('cid', (int) $params['cid']);
        }
        if (isset($params['is_show']) && $params['is_show'] !== '') {
            $query->where('is_show', (int) $params['is_show']);
        }
        foreach (['start_time' => '>=', 'end_time' => '<=', 'start' => '>=', 'end' => '<='] as $field => $operator) {
            if (!isset($params[$field]) || $params[$field] === '') {
                continue;
            }
            $value = in_array($field, ['start_time', 'end_time'], true)
                ? strtotime((string) $params[$field])
                : filter_var($params[$field], FILTER_VALIDATE_INT);
            if ($value === false) {
                throw BusinessException::invalid('ARTICLE_LIST_TIME_INVALID', '查询时间无效');
            }
            $query->where('create_time', $operator, $value);
        }
        $this->applyOrder($query, $params);
        $exportMode = $params['export'] ?? 0;
        if (!in_array($exportMode, [0, 1, 2, '1', '2'], true)) {
            throw BusinessException::invalid('ARTICLE_EXPORT_RANGE_INVALID', '导出模式无效');
        }
        $exportMode = (int) $exportMode;
        if ($exportMode > 0) {
            try {
                // Match the ordinary list's explicit unpaged mode, including its 25000-row bound.
                $pageSize = (int) ($params['page_type'] ?? 1) === 0
                    ? self::PAGE_SIZE_MAX
                    : PaginationInput::from($params, 1, self::PAGE_SIZE_DEFAULT)->pageSize;
            } catch (\InvalidArgumentException $exception) {
                throw BusinessException::invalid('ARTICLE_EXPORT_RANGE_INVALID', $exception->getMessage());
            }
            // Deferred ThinkORM scope callbacks retain their original Query argument.
            // Count on that same query (the native aggregate restores its field projection), not a shallow clone.
            $info = ExportPageInfo::from($query->count(), $pageSize, self::PAGE_SIZE_MAX, '资讯列表');
            if ($exportMode === 1) {
                return $info->toArray();
            }
            foreach (['page_type', 'page_start', 'page_end'] as $field) {
                if (isset($params[$field]) && !is_int($params[$field])
                    && !(is_string($params[$field]) && ctype_digit($params[$field]))) {
                    throw BusinessException::invalid('ARTICLE_EXPORT_RANGE_INVALID', '导出范围必须为整数');
                }
            }
            try {
                [$offset, $limit] = $info->rowRange(
                    (int) ($params['page_type'] ?? 0),
                    (int) ($params['page_start'] ?? 1),
                    isset($params['page_end']) ? (int) $params['page_end'] : null,
                );
            } catch (\InvalidArgumentException $exception) {
                throw BusinessException::invalid('ARTICLE_EXPORT_RANGE_INVALID', $exception->getMessage());
            }
            $pageResult = new PageResult($query->limit($offset, $limit)->select()->toArray(), $info->count, 1, $limit);
        } else {
            $pageResult = $this->paginate($query, $params)
                ->map(static fn(mixed $item): array => $item instanceof \think\Model
                    ? $item->toArray()
                    : (array) $item);
        }
        $rows = $pageResult->items;
        $categoryNames = $this->categoryNames(array_column($rows, 'cid'), $onlyTrashed);
        $imageUrls = $this->assets->forReadMany(array_map(
            static fn(array $row): string => (string) ($row['image'] ?? ''),
            $rows,
        ));
        foreach ($rows as &$row) {
            $row = $this->formatArticleRow($row, $categoryNames, $imageUrls);
        }
        unset($row);
        if ($exportMode === 2) {
            $permission = $onlyTrashed ? 'peanut.article.recycle.list' : 'peanut.article.list';
            $this->assertPermission($context, $permission);
            // Exactly the list's field projection and formatting; no raw tenant/file ledger fields.
            $fields = [...self::articleFields(), 'cate_name', 'click'];
            $file = $this->xlsxExport->create(
                (string) ($params['file_name'] ?? '资讯列表'),
                $fields,
                array_map(static fn(array $row): array => array_map(static fn(string $field): mixed => $row[$field], $fields), $rows),
            );
            $this->assertPermission($context, $permission);
            return ['url' => $file['url'], 'file_name' => $file['original_name']];
        }
        return new PageResult($rows, $pageResult->total, $pageResult->page, $pageResult->pageSize);
    }

    /** @return array<string,mixed> */
    private function findRow(int $id, bool $onlyTrashed): array
    {
        $query = $onlyTrashed ? Article::onlyTrashed() : Article::where([]);
        $article = $query->field(self::articleFields())->where('id', $id)->findOrEmpty();
        if ($article->isEmpty()) {
            return [];
        }
        $row = $article->toArray();
        return $this->formatArticleRow(
            $row,
            $this->categoryNames([(int) $article['cid']], $onlyTrashed),
        );
    }

    private function paginate(BaseQuery $query, array $params): PageResult
    {
        $pageType = (int) ($params['page_type'] ?? 1);
        if ($pageType === 0) {
            return PageResult::fromPaginator($query->paginate([
                'list_rows' => self::PAGE_SIZE_MAX,
                'page' => 1,
                'var_page' => 'page_no',
            ]), 1);
        }
        return PaginationInput::from($params, 1, self::PAGE_SIZE_DEFAULT)->result($query);
    }

    private function applyOrder(BaseQuery $query, array $params): void
    {
        $field = (string) ($params['field'] ?? '');
        $orderBy = strtolower((string) ($params['order_by'] ?? ''));
        if (in_array($field, ['create_time', 'id'], true)
            && in_array($orderBy, ['asc', 'desc'], true)) {
            $query->order($field, $orderBy);
            if ($field !== 'id') {
                $query->order('id', $orderBy);
            }
            return;
        }
        $query->order(['sort' => 'desc', 'id' => 'desc']);
    }

    /** @return list<string> */
    private static function articleFields(): array
    {
        return [
            'id', 'cid', 'title', 'desc', 'abstract', 'image', 'author', 'content',
            'click_virtual', 'click_actual', 'is_show', 'sort', 'body_document_id',
            'create_time', 'update_time', 'delete_time',
        ];
    }

    /** @return array<string,mixed> */
    private function articleWriteData(TenantContext $context, array $params, bool $includeContent = true): array
    {
        $params = array_intersect_key($params, array_flip(self::WRITE_FIELDS));
        $data = [
            'cid' => (int) $params['cid'],
            'title' => (string) $params['title'],
            'desc' => (string) ($params['desc'] ?? ''),
            'abstract' => (string) ($params['abstract'] ?? ''),
            'image' => $this->assets->forStorage((string) ($params['image'] ?? ''), null, $context),
            'author' => (string) ($params['author'] ?? ''),
            'click_virtual' => (int) ($params['click_virtual'] ?? 0),
            'is_show' => (int) $params['is_show'],
            'sort' => (int) ($params['sort'] ?? 0),
        ];
        if ($includeContent) {
            $data['content'] = $this->richText->forStorage((string) ($params['content'] ?? ''), $context);
        }
        return $data;
    }

    /** @return array<int,string> */
    private function categoryNames(array $ids, bool $includeTrashed): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $query = $includeTrashed ? ArticleCate::withTrashed() : ArticleCate::where([]);
        return $query->whereIn('id', $ids)->column('name', 'id');
    }

    private function requireCategory(int $id, bool $lock = false): void
    {
        $query = ArticleCate::where([])->where('id', $id);
        if ($lock) {
            $query->lock(true);
        }
        if ($query->findOrEmpty()->isEmpty()) {
            throw BusinessException::conflict('ARTICLE_CATEGORY_UNAVAILABLE', '所属栏目必须存在且未删除');
        }
    }

    /** @param array<string,mixed> $row @param array<int,string> $categoryNames */
    private function formatArticleRow(array $row, array $categoryNames, ?array $imageUrls = null): array
    {
        foreach (['id', 'cid', 'click_virtual', 'click_actual', 'is_show', 'sort'] as $field) {
            $row[$field] = (int) ($row[$field] ?? 0);
        }
        $row['cate_name'] = (string) ($categoryNames[$row['cid']] ?? '');
        $row['click'] = $row['click_actual'] + $row['click_virtual'];
        $image = trim((string) ($row['image'] ?? ''));
        $row['image'] = $imageUrls === null ? $this->assets->forRead($image) : ($imageUrls[$image] ?? '');
        $row['content'] = $this->richText->forRead((string) ($row['content'] ?? ''));
        $row['body_document_id'] = $row['body_document_id'] === null ? null : (int) $row['body_document_id'];
        foreach (['create_time', 'update_time', 'delete_time'] as $field) {
            $row[$field] = self::formatTime($row[$field] ?? 0);
        }
        return $row;
    }

    /** @param list<int> $ids @return array<string,mixed> */
    private function batch(array $ids, string $successKey, callable $operation): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 100) {
            throw BusinessException::invalid('ARTICLE_BATCH_IDS_INVALID', '操作对象数量须在 1 到 100 之间');
        }
        $result = ['requested' => $ids, $successKey => [], 'already_active' => [], 'failed' => []];
        foreach ($ids as $id) {
            try {
                $status = $operation($id);
                $result[$status][] = $id;
            } catch (BusinessException $exception) {
                $result['failed'][] = [
                    'id' => $id,
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ];
            }
        }
        return $result;
    }

    private function assertPermission(TenantContext $context, string $permission): void
    {
        try {
            $current = $this->executionContext->tenantAdmin();
            $actor = AdminPrincipal::fromArray($this->executionContext->tenantAdminPrincipal());
        } catch (\DomainException) {
            throw BusinessException::forbidden('ARTICLE_ADMIN_PERMISSION_DENIED', '无权管理资讯');
        }
        if ($current->tenantId !== $context->tenantId
            || $current->accountId !== $context->accountId
            || $current->memberId !== $context->memberId
            || $current->authorizationRevision !== $context->authorizationRevision
            || !$this->authorization->decide($context, $actor, $permission)->allowed
        ) {
            throw BusinessException::forbidden('ARTICLE_ADMIN_PERMISSION_DENIED', '无权管理资讯');
        }
    }

    private static function formatTime(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }
        return is_numeric($value) ? date('Y-m-d H:i:s', (int) $value) : (string) $value;
    }
}
