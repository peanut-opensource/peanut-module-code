<?php

declare(strict_types=1);

namespace app\adminapi\infrastructure\generator;

use app\common\http\PageResult;
use app\common\support\PaginationInput;
use think\facade\Db;

/** 仅通过 information_schema 和绑定参数读取数据库元数据。 */
final class ThinkPhpGeneratorMetadata
{
    public function tables(string $keyword, int $pageNo, int $pageSize): PageResult
    {
        $query = Db::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $this->schema())
            ->where('TABLE_TYPE', 'BASE TABLE');
        if ($keyword !== '') {
            $query->where(function ($sub) use ($keyword): void {
                $sub->where('TABLE_NAME', 'like', '%' . $keyword . '%')
                    ->whereOr('TABLE_COMMENT', 'like', '%' . $keyword . '%');
            });
        }

        return PaginationInput::from([
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ])->result($query
            ->field('TABLE_NAME AS table_name,TABLE_COMMENT AS table_comment,ENGINE AS engine,TABLE_ROWS AS table_rows,CREATE_TIME AS create_time')
            ->order('TABLE_NAME', 'asc'));
    }

    public function table(string $tableName): array
    {
        $this->assertIdentifier($tableName, '数据表名称');
        $row = Db::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $this->schema())
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->where('TABLE_NAME', $tableName)
            ->field('TABLE_NAME AS table_name,TABLE_COMMENT AS table_comment')
            ->find();
        if (empty($row)) {
            throw new \RuntimeException('数据表不存在');
        }
        return $row;
    }

    public function columns(string $tableName): array
    {
        $this->table($tableName);
        $rows = Db::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $this->schema())
            ->where('TABLE_NAME', $tableName)
            ->field('COLUMN_NAME AS column_name,COLUMN_COMMENT AS column_comment,COLUMN_TYPE AS column_type,DATA_TYPE AS data_type,IS_NULLABLE AS is_nullable,COLUMN_KEY AS column_key,COLUMN_DEFAULT AS column_default,EXTRA AS extra,ORDINAL_POSITION AS ordinal_position')
            ->order('ORDINAL_POSITION', 'asc')
            ->select()
            ->toArray();

        return array_map([$this, 'columnDefinition'], $rows);
    }

    /** @param list<string> $tableNames @return array<string,array{table_comment:string,columns:list<array<string,mixed>>}> */
    public function definitions(array $tableNames): array
    {
        $tableNames = array_values(array_unique(array_map('strval', $tableNames)));
        if ($tableNames === []) {
            throw new \InvalidArgumentException('请选择数据表');
        }
        foreach ($tableNames as $tableName) {
            $this->assertIdentifier($tableName, '数据表名称');
        }

        $tables = Db::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $this->schema())
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->whereIn('TABLE_NAME', $tableNames)
            ->field('TABLE_NAME AS table_name,TABLE_COMMENT AS table_comment')
            ->select()
            ->toArray();
        $definitions = [];
        foreach ($tables as $table) {
            $definitions[(string) $table['table_name']] = [
                'table_comment' => (string) $table['table_comment'],
                'columns' => [],
            ];
        }
        if (count($definitions) !== count($tableNames)) {
            throw new \RuntimeException('包含不存在的数据表');
        }

        $columns = Db::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $this->schema())
            ->whereIn('TABLE_NAME', $tableNames)
            ->field('TABLE_NAME AS table_name,COLUMN_NAME AS column_name,COLUMN_COMMENT AS column_comment,COLUMN_TYPE AS column_type,DATA_TYPE AS data_type,IS_NULLABLE AS is_nullable,COLUMN_KEY AS column_key,COLUMN_DEFAULT AS column_default,EXTRA AS extra,ORDINAL_POSITION AS ordinal_position')
            ->order(['TABLE_NAME' => 'asc', 'ORDINAL_POSITION' => 'asc'])
            ->select()
            ->toArray();
        foreach ($columns as $column) {
            $definitions[(string) $column['table_name']]['columns'][] = $this->columnDefinition($column);
        }
        return $definitions;
    }

    public function assertIdentifier(string $value, string $label = '标识符'): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $value)) {
            throw new \InvalidArgumentException($label . '格式错误');
        }
    }

    /** @return array<string,mixed> */
    private function columnDefinition(array $column): array
    {
        $name = (string) $column['column_name'];
        $audit = in_array($name, ['id', 'create_time', 'update_time', 'delete_time'], true);
        $primary = (string) $column['column_key'] === 'PRI';
        $auto = str_contains(strtolower((string) $column['extra']), 'auto_increment');
        $phpType = self::phpType((string) $column['data_type']);
        return [
            'column_name' => $name,
            'column_comment' => (string) $column['column_comment'],
            'column_type' => (string) $column['column_type'],
            'php_type' => $phpType,
            'is_required' => (int) ((string) $column['is_nullable'] === 'NO'
                && $column['column_default'] === null && !$primary && !$auto),
            'is_pk' => (int) $primary,
            'is_insert' => (int) (!$audit && !$auto),
            'is_update' => (int) (!$audit && !$auto),
            'is_lists' => (int) (!$audit || $primary),
            'is_query' => (int) (!$audit),
            'query_type' => $phpType === 'string' ? 'like' : '=',
            'view_type' => self::viewType($name, (string) $column['data_type']),
            'dict_type' => '',
            'sort' => (int) $column['ordinal_position'],
        ];
    }

    private function schema(): string
    {
        $rows = Db::query('SELECT DATABASE() AS schema_name');
        $schema = (string) ($rows[0]['schema_name'] ?? '');
        if ($schema === '') {
            throw new \RuntimeException('未选择数据库');
        }
        return $schema;
    }

    private static function phpType(string $type): string
    {
        if (preg_match('/int|bit|serial|bool/i', $type)) {
            return 'int';
        }
        if (preg_match('/decimal|double|float|real|numeric/i', $type)) {
            return 'float';
        }
        if (preg_match('/json/i', $type)) {
            return 'array';
        }
        return 'string';
    }

    private static function viewType(string $name, string $type): string
    {
        if (preg_match('/text|json/i', $type)) {
            return 'textarea';
        }
        if (preg_match('/date|time/i', $type)) {
            return 'datetime';
        }
        if (preg_match('/status|disable|show|enable/i', $name)) {
            return 'switch';
        }
        return 'input';
    }
}
