<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Article\Contract\ArticleDraftImport;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\ColumnDefinition;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\DataProvider;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\DataProviderRegistry;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\ExportBatch;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\RowIssue;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\SchemaDefinition;

final readonly class ArticleDraftCsvProvider implements DataProvider
{
    public function __construct(private ArticleDraftImport $articles) {}

    public function key(): string
    {
        return 'peanut.article.drafts';
    }

    public function schema(): SchemaDefinition
    {
        return new SchemaDefinition('1', [
            new ColumnDefinition('title', 'title', exportable: false, requiredOnImport: true, maxBytes: 1020),
            new ColumnDefinition('cid', 'cid', exportable: false, requiredOnImport: true, maxBytes: 19),
            new ColumnDefinition('body', 'body', exportable: false, maxBytes: 65535),
        ]);
    }

    public function validateImport(AuthorizedOperationContext $context, array $row): array
    {
        DataProviderRegistry::assertOperation($context, 'create');
        $fields = $this->articles->invalidDraftFields($context->tenantContext, [
            'title' => $row['title'],
            'cid' => $row['cid'],
            'body' => $row['body'] ?? '',
        ]);
        return array_map(static fn(string $field): RowIssue => new RowIssue('ARTICLE_DRAFT_FIELD_INVALID', $field), $fields);
    }

    public function importRow(AuthorizedOperationContext $context, array $row, string $idempotencyKey): void
    {
        DataProviderRegistry::assertOperation($context, 'create');
        $this->articles->importDraft($context->tenantContext, $row['title'], (int) $row['cid'], $row['body'] ?? '', $idempotencyKey);
    }

    public function exportBatch(AuthorizedOperationContext $context, ?string $cursor, int $limit): ExportBatch
    {
        throw ImportExportException::denied();
    }
}
