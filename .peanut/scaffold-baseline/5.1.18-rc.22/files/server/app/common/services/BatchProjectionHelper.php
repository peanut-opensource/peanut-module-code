<?php

declare(strict_types=1);

namespace app\common\services;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Enrich only already-authorized page rows using the modules' batch read contracts. */
final class BatchProjectionHelper
{
    /** @param list<array<string,mixed>> $rows @param array<string,string> $fields source => output */
    public function members(TenantContext $context, array $rows, array $fields, callable $lookup): array
    {
        $ids = [];
        foreach ($rows as $row) foreach ($fields as $source => $_) {
            $id = $row[$source] ?? null;
            if (is_numeric($id) && (int) $id > 0) $ids[] = (int) $id;
        }
        $names = $ids === [] ? [] : $lookup($context, array_values(array_unique($ids)));
        foreach ($rows as &$row) foreach ($fields as $source => $target) {
            $row[$target] = $names[(int) ($row[$source] ?? 0)] ?? null;
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,string> $fields source => output */
    public function publicFiles(array $rows, array $fields, callable $lookup): array
    {
        $refs = [];
        foreach ($rows as $row) foreach ($fields as $source => $_) {
            if (is_string($row[$source] ?? null) && trim($row[$source]) !== '') $refs[] = trim($row[$source]);
        }
        $urls = $refs === [] ? [] : $lookup(array_values(array_unique($refs)));
        foreach ($rows as &$row) foreach ($fields as $source => $target) {
            $ref = $row[$source] ?? null;
            $row[$target] = is_string($ref) ? ($urls[trim($ref)] ?? null) : null;
        }
        return $rows;
    }
}
