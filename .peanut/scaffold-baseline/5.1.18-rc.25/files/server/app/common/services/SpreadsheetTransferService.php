<?php

declare(strict_types=1);

namespace app\common\services;

use PeanutAdmin\ImportExport\ColumnTemplate;
use PeanutAdmin\ImportExport\SpreadsheetReader;
use PeanutAdmin\ImportExport\SpreadsheetWriter;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;

/** Stream files without owning an import's business transaction or authorization. */
final class SpreadsheetTransferService
{
    /** @param iterable<iterable<mixed>> $rows */
    public function export(string $path, array $headings, iterable $rows, ?string $sheetName = null): void
    {
        $all = static function () use ($headings, $rows): \Generator {
            yield $headings;
            yield from $rows;
        };
        (new SpreadsheetWriter())->write($path, $all(), $sheetName);
    }

    public function template(string $path, ColumnTemplate $template, ?string $sheetName = null): void
    {
        $this->export($path, array_keys($template->columns), [], $sheetName);
    }

    /** Submit to the existing import-export/Task owner after the caller authorizes the operation. */
    public function submitImport(ImportExportCommands $commands, AuthorizedOperationContext $context, string $providerKey, string $fileKey, array $mapping, string $idempotencyKey): AsyncExportOperation
    {
        return $commands->submitImport($context, $providerKey, $fileKey, $mapping, $idempotencyKey);
    }

    public function submitExport(ImportExportCommands $commands, AuthorizedOperationContext $context, string $providerKey, string $idempotencyKey): AsyncExportOperation
    {
        return $commands->submitExport($context, $providerKey, $idempotencyKey);
    }

    /** @return iterable<array{row:int,values:array<string,mixed>,errors:array<string,string>}> */
    public function rows(string $path, ColumnTemplate $template): iterable
    {
        $headings = array_keys($template->columns);
        $first = true;
        $number = 0;
        foreach ((new SpreadsheetReader())->read($path) as $cells) {
            ++$number;
            if ($first) {
                $first = false;
                if ($cells !== $headings) throw new \InvalidArgumentException('表格列与模板不匹配');
                continue;
            }
            if (count($cells) > count($headings)) throw new \InvalidArgumentException('表格行包含多余的列');
            $values = array_combine($headings, array_pad($cells, count($headings), null));
            yield ['row' => $number, 'values' => $values, 'errors' => $template->validate($values)];
        }
        if ($first) throw new \InvalidArgumentException('表格缺少标题行');
    }

    /** @return list<array{row:int,values:array<string,mixed>,errors:array<string,string>}> */
    public function preview(string $path, ColumnTemplate $template, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('预览行数无效');
        $result = [];
        foreach ($this->rows($path, $template) as $row) {
            $result[] = $row;
            if (count($result) === $limit) break;
        }
        return $result;
    }
}
