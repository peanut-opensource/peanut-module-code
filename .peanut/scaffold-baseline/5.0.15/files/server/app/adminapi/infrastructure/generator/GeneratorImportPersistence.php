<?php

declare(strict_types=1);

namespace app\adminapi\infrastructure\generator;

use app\common\model\generator\GeneratorColumn;
use app\common\model\generator\GeneratorTable;
use app\common\model\generator\GeneratorDownload;
use think\facade\Db;
use app\common\persistence\ConvertsModelPage;

/** Instance-owned persistence for generator metadata and download tokens. */
final readonly class GeneratorImportPersistence
{
    use ConvertsModelPage;

    /**
     * @param list<array{table_name:string,table_comment:string,entity_name:string,columns:list<array<string,mixed>>}> $definitions
     */
    public function import(int $adminId, array $definitions): void
    {
        if ($adminId < 1 || $definitions === []) {
            throw new \InvalidArgumentException('生成器导入参数无效');
        }

        Db::transaction(function () use ($adminId, $definitions): void {
            $tableNames = array_column($definitions, 'table_name');
            $existing = GeneratorTable::where('admin_id', $adminId)
                ->whereIn('table_name', $tableNames)
                ->value('table_name');
            if (is_string($existing) && $existing !== '') {
                throw new \RuntimeException('数据表已导入：' . $existing);
            }

            $tables = (new GeneratorTable())->saveAll(array_map(
                static fn(array $definition): array => [
                    'admin_id' => $adminId,
                    'table_name' => $definition['table_name'],
                    'table_comment' => $definition['table_comment'],
                    'module_name' => 'generated',
                    'entity_name' => $definition['entity_name'],
                    'template_type' => 'crud',
                    'data_owner' => null,
                    'target_edition' => null,
                    'author' => '',
                    'tree_config' => [
                        'soft_delete' => ['enabled' => false, 'field' => ''],
                    ],
                    'relations' => [],
                ],
                $definitions,
            ));
            if ($tables->count() !== count($definitions)) {
                throw new \RuntimeException('生成器父表写入不完整');
            }

            $columnRows = [];
            foreach ($tables as $index => $table) {
                $tableId = (int) $table->id;
                if ($tableId < 1) {
                    throw new \RuntimeException('生成器父表身份无效');
                }
                foreach ($definitions[$index]['columns'] as $column) {
                    $columnRows[] = ['table_id' => $tableId] + $column;
                }
            }
            if ($columnRows !== []) {
                (new GeneratorColumn())->saveAll($columnRows);
            }
        });
    }

    public function tables(int $adminId)
    {
        return GeneratorTable::where('admin_id', $adminId);
    }

    public function columns(int $tableId)
    {
        return GeneratorColumn::where('table_id', $tableId);
    }

    /** @param int[] $tableIds */
    public function columnsForTables(array $tableIds)
    {
        return GeneratorColumn::whereIn('table_id', $tableIds);
    }

    /** @param list<array<string,mixed>> $rows */
    public function saveColumns(array $rows): void
    {
        if ($rows !== []) {
            (new GeneratorColumn())->saveAll($rows);
        }
    }

    /** @param int[] $tableIds */
    public function deleteColumns(array $tableIds): void
    {
        GeneratorColumn::whereIn('table_id', $tableIds)->delete();
    }

    /** @param array<string,mixed> $values */
    public function createDownload(array $values): void
    {
        GeneratorDownload::create($values);
    }

    public function downloads(int $adminId)
    {
        return GeneratorDownload::where('admin_id', $adminId);
    }
}
