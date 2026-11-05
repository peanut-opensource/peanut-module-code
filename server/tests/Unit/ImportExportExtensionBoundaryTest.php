<?php

declare(strict_types=1);

use app\common\infrastructure\export\OperationLogExportProvider;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\CsvExportOperation;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\ColumnDefinition;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\DataProvider;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\ExportBatch;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\RowIssue;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\SchemaDefinition;
use PHPUnit\Framework\TestCase;

/** Existing extension API and pure value rules only; no export job, database or network. */
final class ImportExportExtensionBoundaryTest extends TestCase
{
    public function testProviderPortAndItsPublicValuesAreDeclared(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/peanut/import_export/module.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([DataProvider::class, ColumnDefinition::class, SchemaDefinition::class, ExportBatch::class, RowIssue::class, ImportExportException::class, AsyncExportOperation::class, CsvExportOperation::class] as $type) {
            self::assertContains($type, $manifest['contracts']['exports']);
            self::assertFalse((new ReflectionClass($type))->isSubclassOf(\think\Model::class));
        }
        $source = file_get_contents((new ReflectionClass(OperationLogExportProvider::class))->getFileName());
        preg_match_all('/^use (PeanutAdmin\\\\Modules\\\\ImportExport\\\\[^;]+);/m', $source, $imports);
        self::assertCount(6, $imports[1]);
        foreach ($imports[1] as $type) {
            self::assertContains($type, $manifest['contracts']['exports']);
        }
    }

    public function testOperationLogProviderRetainsItsReadOnlySchema(): void
    {
        $provider = new OperationLogExportProvider();
        self::assertInstanceOf(DataProvider::class, $provider);
        self::assertSame('app.operation-log', $provider->key());
        $columns = $provider->schema()->exportColumns();
        self::assertSame(['id', 'username', 'ip', 'uri', 'method', 'params', 'create_time'], array_map(static fn(ColumnDefinition $column): string => $column->key, $columns));
        foreach ($columns as $column) {
            self::assertFalse($column->importable);
            self::assertTrue($column->exportable);
        }
    }

    public function testExportBatchRetainsRowsAndCursorWithoutPerformingIo(): void
    {
        $rows = [['id' => 1, 'amount' => 0, 'active' => false, 'name' => '', 'optional' => null]];
        $batch = new ExportBatch($rows, '1');
        self::assertSame($rows, $batch->rows);
        self::assertSame('1', $batch->nextCursor);
        self::assertTrue((new ReflectionClass($batch))->isReadOnly());
    }

    public function testExportBatchStillRejectsAnOversizedBatch(): void
    {
        $this->expectException(ImportExportException::class);
        $this->expectExceptionMessage('IMPORT_EXPORT_INVALID');
        new ExportBatch(array_fill(0, 501, ['id' => 1]), null);
    }

    public function testSchemaStillRejectsDuplicateHeadings(): void
    {
        $this->expectException(ImportExportException::class);
        new SchemaDefinition('1', [new ColumnDefinition('first', 'same'), new ColumnDefinition('second', 'same')]);
    }

    public function testSchemaDoesNotPermitImportingAnExportOnlyColumn(): void
    {
        $schema = new SchemaDefinition('1', [new ColumnDefinition('id', 'ID', false, true)]);
        $this->expectException(ImportExportException::class);
        $this->expectExceptionMessage('IMPORT_EXPORT_SCHEMA_MISMATCH');
        $schema->validateImportMapping(['ID' => 'id']);
    }

    public function testRowIssuesCarryCodesNotRawInput(): void
    {
        $issue = new RowIssue('IMPORT_VALUE_REQUIRED', 'name');
        self::assertSame('IMPORT_VALUE_REQUIRED', $issue->code);
        self::assertSame('name', $issue->columnKey);
        $this->expectException(ImportExportException::class);
        new RowIssue('raw input with spaces');
    }
}
