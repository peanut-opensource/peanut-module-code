<?php

declare(strict_types=1);

namespace app\adminapi\services\generator;

use app\adminapi\services\generator\GeneratorArchiveService;
use app\adminapi\infrastructure\generator\GeneratorImportPersistence;
use app\adminapi\infrastructure\generator\ThinkPhpGeneratorMetadata;
use app\adminapi\services\generator\GeneratorRenderService;
use app\common\http\PageResult;
use think\facade\Db;
use app\common\support\PaginationInput;

class GeneratorService
{
    public function __construct(
        private readonly GeneratorImportPersistence $imports,
        private readonly ThinkPhpGeneratorMetadata $metadata,
        private readonly string $databasePrefix,
    ) {}

    public function sourceTables(array $params): PageResult
    {
        $pagination = PaginationInput::from($params);
        return $this->metadata->tables(
            trim((string) ($params['keyword'] ?? '')),
            $pagination->page,
            $pagination->pageSize,
        );
    }

    public function lists(int $adminId, array $params): PageResult
    {
        $pagination = PaginationInput::from($params);
        $query = $this->imports->tables($adminId);
        if (!empty($params['keyword'])) {
            $keyword = trim((string) $params['keyword']);
            $query->where(function ($sub) use ($keyword): void {
                $sub->where('table_name', 'like', '%' . $keyword . '%')
                    ->whereOr('table_comment', 'like', '%' . $keyword . '%')
                    ->whereOr('entity_name', 'like', '%' . $keyword . '%');
            });
        }
        $pageResult = $pagination->result($query->order('id', 'desc'));
        $pageResult = GeneratorImportPersistence::arrayPage($pageResult);
        $lists = array_map(
            static fn(array $table): array => self::hydrateSoftDelete($table),
            $pageResult->items,
        );
        return new PageResult($lists, $pageResult->total, $pageResult->page, $pageResult->pageSize);
    }

    public function detail(int $adminId, int $id): array
    {
        return $this->ownedTable($adminId, $id, true);
    }

    public function importTables(int $adminId, array $tableNames): bool
    {
        $tableNames = array_values(array_unique(array_map('strval', $tableNames)));
        $metadata = $this->metadata->definitions($tableNames);
        $definitions = [];
        foreach ($tableNames as $tableName) {
            $entityName = $this->entityName($tableName);
            self::assertEntity($entityName);
            $definitions[] = [
                'table_name' => $tableName,
                'table_comment' => $metadata[$tableName]['table_comment'],
                'entity_name' => $entityName,
                'columns' => $metadata[$tableName]['columns'],
            ];
        }
        $this->imports->import($adminId, $definitions);
        return true;
    }

    public function sync(int $adminId, int $id): bool
    {
        Db::transaction(function () use ($adminId, $id): void {
            $table = $this->ownedTableModel($adminId, $id, true);
            $metadata = $this->metadata->columns((string) $table->table_name);
            $existing = [];
            foreach ($this->imports->columns($id)->select() as $row) {
                $existing[(string) $row->column_name] = $row;
            }
            $seen = [];
            $persist = [];
            foreach ($metadata as $column) {
                $name = (string) $column['column_name'];
                $seen[] = $name;
                if (isset($existing[$name])) {
                    $row = $existing[$name];
                    $persist[] = [
                        'id' => (int) $row->id,
                        'column_type' => $column['column_type'],
                        'php_type' => $column['php_type'],
                        'is_required' => $column['is_required'],
                        'is_pk' => $column['is_pk'],
                        'sort' => $column['sort'],
                    ];
                } else {
                    $persist[] = ['table_id' => $id] + $column;
                }
            }
            if ($persist !== []) {
                $this->imports->saveColumns($persist);
            }
            $delete = $this->imports->columns($id);
            if ($seen !== []) {
                $delete->whereNotIn('column_name', $seen);
            }
            $delete->delete();
            $table->save(['table_comment' => (string) $this->metadata->table((string) $table->table_name)['table_comment']]);
        });
        return true;
    }

    public function update(int $adminId, array $params): bool
    {
        Db::transaction(function () use ($adminId, $params): void {
            $id = (int) $params['id'];
            $table = $this->ownedTableModel($adminId, $id, true);
            $module = trim((string) $params['module_name']);
            $entity = trim((string) $params['entity_name']);
            self::assertModule($module);
            GeneratorRenderService::assertRegisteredModule($module);
            self::assertEntity($entity);

            $columns = [];
            foreach ($this->imports->columns($id)->select() as $column) {
                $columns[(int) $column->id] = $column;
            }
            $columnNames = array_map('strval', array_column(array_map(
                static fn($column): array => $column->toArray(),
                array_values($columns),
            ), 'column_name'));
            $primaryNames = array_values(array_map(
                static fn($column): string => (string) $column->column_name,
                array_filter($columns, static fn($column): bool => (int) $column->is_pk === 1),
            ));
            $relations = $this->normalizeRelations(
                $adminId,
                $params['relations'] ?? [],
                $columnNames,
                $module,
                (string) $params['target_edition'],
            );
            $tree = self::normalizeTree($params['tree_config'] ?? [], $columnNames, (string) $params['template_type']);
            $softDelete = self::normalizeSoftDelete($params['soft_delete'] ?? [], $columnNames, $primaryNames);

            $submittedIds = [];
            $persist = [];
            foreach ($params['columns'] as $column) {
                $columnId = (int) ($column['id'] ?? 0);
                if (!isset($columns[$columnId])) {
                    throw new \RuntimeException('字段不属于当前数据表');
                }
                $submittedIds[] = $columnId;
                $persist[] = [
                    'id' => $columnId,
                    'column_comment' => trim((string) ($column['column_comment'] ?? '')),
                    'is_required' => self::flag($column['is_required'] ?? 0),
                    'is_insert' => self::flag($column['is_insert'] ?? 0),
                    'is_update' => self::flag($column['is_update'] ?? 0),
                    'is_lists' => self::flag($column['is_lists'] ?? 0),
                    'is_query' => self::flag($column['is_query'] ?? 0),
                    'query_type' => self::choice((string) ($column['query_type'] ?? '='), ['=', '<>', '>', '>=', '<', '<=', 'like', 'between']),
                    'view_type' => self::choice((string) ($column['view_type'] ?? 'input'), ['input', 'textarea', 'select', 'radio', 'checkbox', 'switch', 'date', 'datetime', 'number']),
                    'dict_type' => trim((string) ($column['dict_type'] ?? '')),
                ];
            }
            if (count(array_unique($submittedIds)) !== count($columns)) {
                throw new \RuntimeException('必须提交当前数据表的全部字段配置');
            }
            if ($persist !== []) {
                $this->imports->saveColumns($persist);
            }

            $table->save([
                'table_comment' => trim((string) $params['table_comment']),
                'module_name' => $module,
                'entity_name' => $entity,
                'template_type' => (string) $params['template_type'],
                'data_owner' => (string) $params['data_owner'],
                'target_edition' => (string) $params['target_edition'],
                'author' => trim((string) ($params['author'] ?? '')),
                'tree_config' => $tree + ['soft_delete' => $softDelete],
                'relations' => $relations,
            ]);
        });
        return true;
    }

    public function delete(int $adminId, array $ids): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        Db::transaction(function () use ($adminId, $ids): void {
            foreach ($this->imports->tables($adminId)->whereNotIn('id', $ids)->select() as $table) {
                foreach ((array) $table->relations as $relation) {
                    if (in_array((int) ($relation['target_table_id'] ?? 0), $ids, true)) {
                        throw new \RuntimeException('生成配置仍被其他关系引用，不能删除');
                    }
                }
            }
            $owned = $this->imports->tables($adminId)->whereIn('id', $ids)->column('id');
            if (count($owned) !== count($ids)) {
                throw new \RuntimeException('生成配置不存在或无权访问');
            }
            $this->imports->deleteColumns($ids);
            $this->imports->tables($adminId)->whereIn('id', $ids)->delete();
        });
        return true;
    }

    public function preview(int $adminId, int $id): array
    {
        $tables = $this->snapshotTables($adminId, [$id]);
        return GeneratorRenderService::render($tables[0]);
    }

    public function generate(int $adminId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $tables = $this->snapshotTables($adminId, $ids);
        $files = [];
        $mergeGuide = [
            '# Generator merge preview',
            '',
            'Create files are stored at their final repository paths. Existing Module-owned files are never overwritten:',
            'their complete proposed contents are stored under `merge-preview/` and must be applied only after the recorded SHA-256 still matches.',
            '',
        ];
        foreach ($tables as $table) {
            foreach (GeneratorRenderService::render($table) as $file) {
                $path = (string) $file['path'];
                $operation = (string) ($file['operation'] ?? 'create');
                $archivePath = $operation === 'merge' ? 'merge-preview/' . $path : $path;
                if (isset($files[$archivePath])) {
                    throw new \RuntimeException('同一批次不能向同一个目标文件生成多个合并预览，请分批生成：' . $path);
                }
                $file['path'] = $archivePath;
                $files[$archivePath] = $file;
                if ($operation === 'merge') {
                    $mergeGuide[] = '- target: `' . $path . '`';
                    $mergeGuide[] = '  preview: `' . $archivePath . '`';
                    $mergeGuide[] = '  base_sha256: `' . (string) ($file['base_sha256'] ?? '') . '`';
                }
            }
        }
        $files['GENERATOR-MERGE-GUIDE.md'] = [
            'path' => 'GENERATOR-MERGE-GUIDE.md',
            'content' => implode("\n", $mergeGuide) . "\n",
        ];

        $archive = GeneratorArchiveService::create(
            array_values($files),
            $adminId,
            'peanut-code-' . date('YmdHis') . '.zip',
        );
        $token = bin2hex(random_bytes(32));
        try {
            $this->imports->createDownload([
                'admin_id' => $adminId,
                'token_hash' => hash('sha256', $token),
                'archive_path' => $archive['archive_path'],
                'download_name' => $archive['download_name'],
                'expire_time' => time() + 600,
                'used_time' => 0,
            ]);
        } catch (\Throwable $e) {
            GeneratorArchiveService::cleanup($archive['archive_path'], $adminId);
            throw $e;
        }
        return ['download_token' => $token, 'file_name' => $archive['download_name'], 'expires_in' => 600];
    }

    public function consumeDownload(int $adminId, string $token): array
    {
        return Db::transaction(function () use ($adminId, $token): array {
            $row = $this->imports->downloads($adminId)->where([
                'token_hash' => hash('sha256', $token),
                'used_time' => 0,
            ])->where('expire_time', '>', time())->lock(true)->findOrEmpty();
            if ($row->isEmpty()) {
                throw new \RuntimeException('下载令牌无效或已过期');
            }
            $path = GeneratorArchiveService::resolve((string) $row->archive_path, $adminId);
            $row->save(['used_time' => time()]);
            return [
                'path' => $path,
                'file_name' => (string) $row->download_name,
                'archive_path' => (string) $row->archive_path,
            ];
        });
    }

    public function models(int $adminId): array
    {
        return $this->imports->tables($adminId)
            ->field('id,module_name,entity_name,table_name,data_owner,target_edition')
            ->order('entity_name', 'asc')->select()->toArray();
    }

    private function ownedTable(int $adminId, int $id, bool $withColumns): array
    {
        $query = $this->imports->tables($adminId)->where('id', $id);
        if ($withColumns) {
            $query->with('columns');
        }
        $table = $query->findOrEmpty();
        if ($table->isEmpty()) {
            throw new \RuntimeException('生成配置不存在或无权访问');
        }
        return self::hydrateSoftDelete($this->hydrateRelations($adminId, $table->toArray()));
    }

    private function ownedTableModel(int $adminId, int $id, bool $lock = false): object
    {
        $query = $this->imports->tables($adminId)->where('id', $id);
        if ($lock) {
            $query->lock(true);
        }
        $table = $query->findOrEmpty();
        if ($table->isEmpty()) {
            throw new \RuntimeException('生成配置不存在或无权访问');
        }
        return $table;
    }

    private function entityName(string $tableName): string
    {
        $name = $this->databasePrefix !== '' && str_starts_with($tableName, $this->databasePrefix)
            ? substr($tableName, strlen($this->databasePrefix)) : $tableName;
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    private static function assertModule(string $module): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}(?:\.[a-z][a-z0-9-]{0,31})?$/D', $module)) {
            throw new \InvalidArgumentException('模块名称格式错误');
        }
    }

    private static function assertEntity(string $entity): void
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/D', $entity)) {
            throw new \InvalidArgumentException('实体名称格式错误');
        }
    }

    private function normalizeRelations(
        int $adminId,
        mixed $relations,
        array $columnNames,
        string $module,
        string $edition,
    ): array {
        if (!is_array($relations) || count($relations) > 20) {
            throw new \InvalidArgumentException('关系配置格式错误');
        }
        $normalized = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                throw new \InvalidArgumentException('关系配置格式错误');
            }
            $name = (string) ($relation['name'] ?? '');
            $targetTableId = (int) ($relation['target_table_id'] ?? 0);
            if ($targetTableId <= 0) {
                throw new \InvalidArgumentException('关系目标配置无效');
            }
            $type = self::choice((string) ($relation['type'] ?? ''), ['belongsTo', 'hasOne', 'hasMany']);
            $this->metadata->assertIdentifier($name, '关系名称');
            $local = (string) ($relation['local_key'] ?? 'id');
            $foreign = (string) ($relation['foreign_key'] ?? 'id');
            $summaryFields = $relation['summary_fields'] ?? [];
            if (!is_array($summaryFields) || count($summaryFields) > 12) {
                throw new \InvalidArgumentException('关系摘要字段配置无效');
            }
            $summaryFields = array_values(array_unique(array_map('strval', $summaryFields)));
            $this->metadata->assertIdentifier($local, '本地键');
            $this->metadata->assertIdentifier($foreign, '外键');
            foreach ($summaryFields as $summaryField) {
                $this->metadata->assertIdentifier($summaryField, '关系摘要字段');
            }
            if (!in_array($local, $columnNames, true)) {
                throw new \InvalidArgumentException('关系本地字段不存在');
            }
            $normalized[] = [
                'target_table_id' => $targetTableId,
                'name' => $name,
                'type' => $type,
                'local_key' => $local,
                'foreign_key' => $foreign,
                'summary_fields' => $summaryFields,
            ];
        }
        if ($normalized === []) {
            return [];
        }

        $targetIds = array_values(array_unique(array_column($normalized, 'target_table_id')));
        sort($targetIds);
        $targetRows = $this->imports->tables($adminId)->whereIn('id', $targetIds)
            ->order('id', 'asc')->lock(true)->select();
        $targets = [];
        foreach ($targetRows as $target) {
            $targets[(int) $target->id] = $target;
        }
        if (count($targets) !== count($targetIds)) {
            throw new \RuntimeException('关系目标配置不存在或无权访问');
        }
        $targetColumns = [];
        $targetColumnTypes = [];
        foreach ($this->imports->columnsForTables($targetIds)
            ->order(['table_id' => 'asc', 'sort' => 'asc'])->select()->toArray() as $column) {
            $targetColumns[(int) $column['table_id']][] = (string) $column['column_name'];
            $targetColumnTypes[(int) $column['table_id']][(string) $column['column_name']]
                = (string) $column['php_type'];
        }
        foreach ($normalized as &$relation) {
            $target = $targets[(int) $relation['target_table_id']];
            if ((string) $target->module_name !== $module) {
                throw new \InvalidArgumentException('不允许跨模块 ORM 关联；请使用目标模块公开 query 合同');
            }
            if ((string) $target->data_owner !== 'tenant-orm'
                || (string) $target->target_edition !== $edition) {
                throw new \InvalidArgumentException('同模块 ORM 关联必须保持 tenant-orm 所有权且 Edition 一致');
            }
            if (!in_array(
                $relation['foreign_key'],
                $targetColumns[(int) $relation['target_table_id']] ?? [],
                true,
            )) {
                throw new \InvalidArgumentException('关系目标字段不存在');
            }
            foreach ($relation['summary_fields'] as $summaryField) {
                if (!in_array($summaryField, $targetColumns[(int) $relation['target_table_id']] ?? [], true)) {
                    throw new \InvalidArgumentException('关系摘要字段不存在');
                }
                if (in_array($summaryField, ['tenant_id', 'delete_time'], true)
                    || preg_match('/(?:password|passwd|secret|token|credential|private_key|api_key|access_key|refresh_key|salt|digest|hash)$/i', $summaryField) === 1) {
                    throw new \InvalidArgumentException('关系摘要不能公开租户、删除或秘密字段');
                }
            }
            $relation['summary_types'] = array_intersect_key(
                $targetColumnTypes[(int) $relation['target_table_id']] ?? [],
                array_flip($relation['summary_fields']),
            );
        }
        unset($relation);
        return $normalized;
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshotTables(int $adminId, array $ids): array
    {
        sort($ids);
        return Db::transaction(function () use ($adminId, $ids): array {
            $models = $this->imports->tables($adminId)
                ->whereIn('id', $ids)->order('id', 'asc')->lock(true)->select();
            if ($models->count() !== count($ids)) {
                throw new \RuntimeException('生成配置不存在或无权访问');
            }
            $columnsByTable = [];
            foreach ($this->imports->columnsForTables($ids)
                ->order(['table_id' => 'asc', 'sort' => 'asc'])->lock(true)->select()->toArray() as $column) {
                $columnsByTable[(int) $column['table_id']][] = $column;
            }
            $tables = [];
            foreach ($models as $model) {
                $table = $model->toArray();
                $table['columns'] = $columnsByTable[(int) $model->id] ?? [];
                $tables[] = $table;
            }
            $targets = $this->relationTargets($adminId, $tables, true);
            foreach ($tables as &$table) {
                $table = self::hydrateSoftDelete(self::hydrateRelationsFromTargets($table, $targets));
            }
            unset($table);
            return $tables;
        });
    }

    private function hydrateRelations(int $adminId, array $table, bool $lock = false): array
    {
        $targets = $this->relationTargets($adminId, [$table], $lock);
        return self::hydrateRelationsFromTargets($table, $targets);
    }

    /**
     * @param array<int,array<string,mixed>> $tables
     * @return array<int,object>
     */
    private function relationTargets(int $adminId, array $tables, bool $lock): array
    {
        $targetIds = [];
        foreach ($tables as $table) {
            foreach (array_values((array) ($table['relations'] ?? [])) as $relation) {
                $targetIds[] = (int) ($relation['target_table_id'] ?? 0);
            }
        }
        $targetIds = array_values(array_unique(array_filter($targetIds, static fn(int $id): bool => $id > 0)));
        sort($targetIds);
        if ($targetIds === []) {
            return [];
        }

        $query = $this->imports->tables($adminId)
            ->whereIn('id', $targetIds)
            ->order('id', 'asc');
        if ($lock) {
            $query->lock(true);
        }
        $targets = [];
        foreach ($query->select() as $target) {
            $targets[(int) $target->id] = $target;
        }
        if (count($targets) !== count($targetIds)) {
            throw new \RuntimeException('关系目标配置不存在或无权访问');
        }
        return $targets;
    }

    /** @param array<int,object> $targets */
    private static function hydrateRelationsFromTargets(array $table, array $targets): array
    {
        $relations = array_values((array) ($table['relations'] ?? []));
        if ($relations === []) {
            $table['relations'] = [];
            return $table;
        }
        foreach ($relations as &$relation) {
            $target = $targets[(int) $relation['target_table_id']] ?? null;
            if (!is_object($target)) {
                throw new \RuntimeException('关系目标配置不存在或无权访问');
            }
            $relation['module'] = (string) $target->module_name;
            $relation['model'] = (string) $target->entity_name;
            $relation['data_owner'] = (string) $target->data_owner;
            $relation['target_edition'] = (string) $target->target_edition;
            $targetConfig = is_array($target->tree_config ?? null) ? $target->tree_config : [];
            $targetSoftDelete = is_array($targetConfig['soft_delete'] ?? null)
                ? $targetConfig['soft_delete']
                : [];
            $relation['soft_delete_enabled'] = ($targetSoftDelete['enabled'] ?? false) === true;
            $relation['soft_delete_field'] = (string) ($targetSoftDelete['field'] ?? '');
        }
        unset($relation);
        $table['relations'] = $relations;
        return $table;
    }

    /** 将现有 JSON 存储映射为生成定义的稳定顶层字段。 */
    private static function hydrateSoftDelete(array $table): array
    {
        $config = is_array($table['tree_config'] ?? null) ? $table['tree_config'] : [];
        $softDelete = is_array($config['soft_delete'] ?? null) ? $config['soft_delete'] : [];
        $table['soft_delete'] = [
            'enabled' => ($softDelete['enabled'] ?? false) === true,
            'field' => (string) ($softDelete['field'] ?? ''),
        ];
        return $table;
    }

    private static function normalizeTree($tree, array $columnNames, string $templateType): array
    {
        if ($templateType === 'crud') {
            return [];
        }
        if (!is_array($tree)) {
            throw new \InvalidArgumentException('树配置格式错误');
        }
        $result = [
            'id_field' => (string) ($tree['id_field'] ?? ''),
            'parent_field' => (string) ($tree['parent_field'] ?? ''),
            'name_field' => (string) ($tree['name_field'] ?? ''),
        ];
        foreach ($result as $field) {
            if (!in_array($field, $columnNames, true)) {
                throw new \InvalidArgumentException('树配置字段不存在');
            }
        }
        if ($result['id_field'] === $result['parent_field']) {
            throw new \InvalidArgumentException('树主键和父级字段不能相同');
        }
        return $result;
    }

    /** @return array{enabled:bool,field:string} */
    private static function normalizeSoftDelete(mixed $softDelete, array $columnNames, array $primaryNames): array
    {
        if (!is_array($softDelete)) {
            throw new \InvalidArgumentException('软删除配置格式错误');
        }
        if (array_diff(array_keys($softDelete), ['enabled', 'field']) !== []) {
            throw new \InvalidArgumentException('软删除配置包含未声明字段');
        }
        $enabled = $softDelete['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException('软删除 enabled 必须是布尔值');
        }
        $field = trim((string) ($softDelete['field'] ?? ''));
        if (!$enabled) {
            return ['enabled' => false, 'field' => ''];
        }
        if ($field === '' || !in_array($field, $columnNames, true)) {
            throw new \InvalidArgumentException('启用软删除时必须选择当前表的软删除字段');
        }
        if ($field === 'tenant_id' || in_array($field, $primaryNames, true)) {
            throw new \InvalidArgumentException('主键和租户字段不能作为软删除字段');
        }
        return ['enabled' => true, 'field' => $field];
    }

    private static function flag($value): int
    {
        if (!in_array($value, [0, 1, '0', '1'], true)) {
            throw new \InvalidArgumentException('字段开关值错误');
        }
        return (int) $value;
    }

    private static function choice(string $value, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('配置枚举值错误');
        }
        return $value;
    }
}
