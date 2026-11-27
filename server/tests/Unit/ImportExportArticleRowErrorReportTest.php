<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Article\Contract\ArticleDraftImport;
use PeanutAdmin\Modules\Article\Service\ArticleDraftCsvProvider;
use PHPUnit\Framework\TestCase;

final class ImportExportArticleRowErrorReportTest extends TestCase
{
    public function testRedactedErrorCsvFromOfficialArticleDraftSchemaMatchesFrontendPreviewFixture(): void
    {
        $provider = new ArticleDraftCsvProvider(new class implements ArticleDraftImport {
            public function invalidDraftFields(TenantContext $context, array $row): array
            {
                throw new LogicException('The sample only validates schema, never business records');
            }

            public function importDraft(TenantContext $context, string $title, int $categoryId, string $body, string $rowKey): void
            {
                throw new LogicException('The sample must never create an article');
            }
        });
        self::assertSame('peanut.article.drafts', $provider->key());
        $schema = $provider->schema();
        $mapping = $schema->validateImportMapping(['title' => 'title', 'cid' => 'cid', 'body' => 'body']);
        $out = fopen('php://temp', 'w+b');
        self::assertIsResource($out);
        fputcsv($out, ['row_number', 'column_key', 'error_code'], ',', '"', '');
        $issues = [];
        foreach ([
            3 => ['', '12', '<p>Public sample</p>'],
            4 => ['Public sample', '12', str_repeat('x', 65536)],
        ] as $line => $values) {
            $normalized = $schema->normalizeImportRow($values, ['title', 'cid', 'body'], $mapping);
            foreach ($normalized['issues'] as $issue) {
                $issues[] = [$line, $issue->columnKey, $issue->code];
                fputcsv($out, [$line, $issue->columnKey ?? '', $issue->code], ',', '"', '');
            }
        }
        self::assertSame([
            [3, 'title', 'IMPORT_VALUE_REQUIRED'],
            [4, 'body', 'IMPORT_VALUE_TOO_LONG'],
        ], $issues);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        self::assertIsString($csv);
        self::assertStringNotContainsString('Public sample', $csv);
        self::assertStringNotContainsString('<p>', $csv);
        $fixture = dirname(__DIR__, 3) . '/web/src/modules/peanut-import-export/optional-runtime/tests/fixtures/article-draft-errors.csv';
        self::assertSame(file_get_contents($fixture), $csv);
    }
}
