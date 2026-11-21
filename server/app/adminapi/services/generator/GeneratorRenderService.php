<?php

declare(strict_types=1);

namespace app\adminapi\services\generator;

use app\common\exception\BusinessException;
use RuntimeException;

/** 将已完成归属校验的表配置渲染为现有 Module 可装配的 CRUD 代码和合并预览。 */
final class GeneratorRenderService
{
    /**
     * @param array<string,mixed> $table 已由调用方校验归属的生成器表快照
     * @return list<array{path:string,language:string,content:string,operation:string,base_sha256?:string}>
     */
    public static function render(array $table, ?string $sourceRoot = null): array
    {
        $root = self::repositoryRoot($sourceRoot);
        $context = self::context($table, $root);
        $files = [
            self::createFile($context['modelPath'], 'php', self::renderModel($context), $root),
            self::createFile($context['servicePath'], 'php', self::renderService($context), $root),
            self::createFile($context['controllerPath'], 'php', self::renderController($context), $root),
            self::createFile($context['validatePath'], 'php', self::renderValidate($context), $root),
            self::createFile($context['apiPath'], 'typescript', self::renderApi($context), $root),
            self::createFile($context['viewPath'], 'vue', self::renderView($context), $root),
            self::createFile($context['frontendContributionFragmentPath'], 'typescript', self::renderFrontendContribution($context), $root),
            self::createFile($context['routeFragmentPath'], 'php', self::renderRouteFragment($context), $root),
            self::createFile($context['openApiFragmentPath'], 'php', self::renderOpenApiFragment($context), $root),
        ];

        foreach ([
            ['manifestPath', 'json', self::mergeManifest($context), 'manifestSource'],
            ['permissionsPath', 'json', self::mergePermissions($context), 'permissionsSource'],
            ['routePath', 'php', self::mergeRouteRegistry($context), 'routeSource'],
            ['frontendContributionPath', 'typescript', self::mergeFrontendContribution($context), 'frontendContributionSource'],
        ] as [$pathKey, $language, $content, $sourceKey]) {
            $files[] = self::mergeFile($context[$pathKey], $language, $content, $context[$sourceKey]);
        }
        $openApiRegistry = self::mergeOpenApiRegistry($context);
        $files[] = $context['openApiExists']
            ? self::mergeFile($context['openApiPath'], 'php', $openApiRegistry, $context['openApiSource'])
            : self::createFile($context['openApiPath'], 'php', $openApiRegistry, $root);
        return $files;
    }

    /** 保存配置时也可调用；目标必须是现有唯一 Module，而不是任意目录名。 */
    public static function assertRegisteredModule(string $module, ?string $sourceRoot = null): void
    {
        self::module($module, self::repositoryRoot($sourceRoot));
    }

    /** @return array{path:string,language:string,content:string,operation:string} */
    private static function createFile(string $path, string $language, string $content, string $root): array
    {
        $absolute = $root . '/' . $path;
        if (file_exists($absolute) || is_link($absolute)) {
            throw new RuntimeException('生成目标已存在，不能覆盖：' . $path);
        }
        return compact('path', 'language', 'content') + ['operation' => 'create'];
    }

    /** @return array{path:string,language:string,content:string,operation:string,base_sha256:string} */
    private static function mergeFile(string $path, string $language, string $content, string $base): array
    {
        return compact('path', 'language', 'content') + [
            'operation' => 'merge',
            'base_sha256' => hash('sha256', $base),
        ];
    }

    /** @return array<string,mixed> */
    private static function context(array $table, string $root): array
    {
        $tableName = trim((string) ($table['table_name'] ?? $table['name'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $tableName) !== 1) {
            throw new RuntimeException('数据表名称不符合生成规范');
        }
        $rawColumns = $table['columns'] ?? [];
        if (is_string($rawColumns)) {
            $rawColumns = json_decode($rawColumns, true) ?? [];
        }
        if (!is_array($rawColumns) || $rawColumns === []) {
            throw new RuntimeException('数据表字段不能为空');
        }
        $columns = self::columns($rawColumns);
        $primary = self::primaryColumn($columns);

        $module = self::module(trim((string) ($table['module_name'] ?? '')), $root);
        $entity = trim((string) ($table['entity_name'] ?? ''));
        if (preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/D', $entity) !== 1) {
            throw new RuntimeException('实体名称不符合生成规范');
        }
        $owner = strtolower(trim((string) ($table['data_owner'] ?? $table['owner'] ?? '')));
        $owner = match ($owner) {
            'tenant', 'tenant_owned', 'tenant-orm' => 'tenant-orm',
            'platform', 'instance', 'shared' => $owner,
            default => throw new RuntimeException('生成配置必须声明有效的数据所有权'),
        };
        $edition = strtolower(trim((string) ($table['target_edition'] ?? $table['edition'] ?? '')));
        if (!in_array($edition, ['multi-tenant', 'standalone'], true)) {
            throw new RuntimeException('生成配置必须声明 standalone 或 multi-tenant Edition');
        }
        if ($owner !== 'tenant-orm') {
            throw new RuntimeException('当前管理端 CRUD 模板仅支持 tenant-orm 数据所有权');
        }
        if (!in_array('tenant_id', array_column($columns, 'name'), true)) {
            throw new RuntimeException('Tenant-owned 生成实体必须包含 tenant_id 字段');
        }

        $resource = self::kebab($entity);
        $title = self::plainText((string) ($table['table_comment'] ?? $table['comment'] ?? $entity));
        $templateType = strtolower(trim((string) ($table['template_type'] ?? 'crud')));
        if (!in_array($templateType, ['crud', 'tree'], true)) {
            throw new RuntimeException('生成模板类型无效');
        }
        $storedConfig = $table['tree_config'] ?? [];
        if (is_string($storedConfig)) {
            $storedConfig = json_decode($storedConfig, true) ?? [];
        }
        if (!is_array($storedConfig)) {
            throw new RuntimeException('生成配置格式无效');
        }
        $softDelete = self::softDeleteConfig(
            $table['soft_delete'] ?? ($storedConfig['soft_delete'] ?? []),
            $columns,
            $primary,
        );
        $tree = self::treeConfig($storedConfig, $columns, $templateType);
        $capabilities = self::capabilities($storedConfig['capabilities'] ?? [], $columns, $primary, $softDelete['field']);
        $relations = self::relations($table['relations'] ?? [], $module, $edition, $root);
        $projection = self::projection($columns, $primary, $tree, $softDelete['field']);
        $backend = $module['backendRelative'];
        $frontend = $module['frontendRelative'];
        $namespace = $module['namespace'];
        $permissionPrefix = $module['key'] . '.' . $resource;
        $primaryType = self::primaryType($primary);
        $openApiPath = $backend . '/api/metadata/openapi.php';
        $openApiAbsolute = $root . '/' . $openApiPath;
        $openApiSource = is_file($openApiAbsolute) ? self::readFile($openApiPath, $root) : '';

        return $module + [
            'entity' => $entity,
            'resource' => $resource,
            'tableDeclaration' => !empty($table['use_physical_table'])
                ? 'protected $table = ' . var_export($tableName, true) . ';'
                : 'protected $name = ' . var_export(str_starts_with($tableName, 'pa_') ? substr($tableName, 3) : $tableName, true) . ';',
            'databaseTable' => $tableName,
            'title' => $title !== '' ? $title : $entity,
            'columns' => $columns,
            'primary' => $primary['name'],
            'primaryType' => $primaryType,
            'primaryTsType' => $primaryType === 'int' ? 'number' : 'string',
            'primaryLength' => (int) $primary['length'],
            'primaryRule' => self::primaryRule($primary, $primaryType),
            'tree' => $tree,
            'capabilities' => $capabilities,
            'relations' => $relations,
            'owner' => $owner,
            'edition' => $edition,
            'softDelete' => $softDelete['enabled'],
            'softDeleteField' => $softDelete['field'],
            'permissionPrefix' => $permissionPrefix,
            'errorPrefix' => strtoupper(str_replace('-', '_', $resource)),
            'listFields' => $projection['list'],
            'detailFields' => $projection['detail'],
            'modelPath' => "{$backend}/src/Model/{$entity}.php",
            'servicePath' => "{$backend}/src/Service/{$entity}Service.php",
            'controllerPath' => "{$backend}/src/Controller/{$entity}Controller.php",
            'validatePath' => "{$backend}/src/Validation/{$entity}Validate.php",
            'apiPath' => "{$frontend}/generated/{$resource}/api.ts",
            'viewPath' => "{$frontend}/generated/{$resource}/index.vue",
            'frontendContributionFragmentPath' => "{$frontend}/generated/{$resource}/contribution.ts",
            'routeFragmentPath' => "{$backend}/route/generated/{$resource}.php",
            'openApiFragmentPath' => "{$backend}/api/metadata/generated/{$resource}.php",
            'openApiPath' => $openApiPath,
            'openApiSource' => $openApiSource,
            'openApiExists' => is_file($openApiAbsolute),
        ];
    }

    /** @return array<string,mixed> */
    private static function module(string $input, string $root): array
    {
        if ($input === '' || preg_match('/^[a-z][a-z0-9_-]{0,31}(?:\.[a-z][a-z0-9-]{0,31})?$/D', $input) !== 1) {
            throw new RuntimeException('目标模块标识格式错误');
        }
        $matches = [];
        foreach (glob($root . '/server/app/modules/*/*/module.json') ?: [] as $manifestAbsolute) {
            $manifestPath = substr($manifestAbsolute, strlen($root) + 1);
            $source = self::readFile($manifestPath, $root);
            try {
                $manifest = json_decode($source, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('已登记模块清单无效：' . $manifestPath, 0, $exception);
            }
            $key = is_array($manifest) ? (string) ($manifest['key'] ?? '') : '';
            $parts = explode('.', $key);
            $leaf = (string) end($parts);
            $directoryLeaf = basename(dirname($manifestAbsolute));
            if ($key === $input
                || str_replace('-', '_', $leaf) === str_replace('-', '_', $input)
                || $directoryLeaf === str_replace('-', '_', $input)) {
                $matches[] = [$manifestPath, $source, $manifest];
            }
        }
        if ($matches === []) {
            throw BusinessException::invalid(
                'GENERATOR_MODULE_NOT_REGISTERED',
                '目标模块未登记，请先使用现有 module:create/module:check 机制创建模块',
            );
        }
        if (count($matches) !== 1) {
            throw BusinessException::invalid(
                'GENERATOR_MODULE_AMBIGUOUS',
                '目标模块名称不唯一，请使用完整 Module key',
            );
        }

        [$manifestPath, $manifestSource, $manifest] = $matches[0];
        $backendRelative = dirname($manifestPath);
        try {
            $composer = json_decode(self::readFile($backendRelative . '/composer.json', $root), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('目标模块 Composer 声明无效', 0, $exception);
        }
        $psr4 = $composer['autoload']['psr-4'] ?? null;
        if (!is_array($psr4) || count($psr4) !== 1 || current($psr4) !== 'src/') {
            throw new RuntimeException('目标模块必须声明唯一且指向 src/ 的 PSR-4 前缀');
        }
        $namespace = (string) array_key_first($psr4);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+\\\\$/D', $namespace) !== 1) {
            throw new RuntimeException('目标模块 PHP 命名空间无效');
        }
        $frontendEntry = $manifest['frontend']['entry'] ?? $manifest['frontend']['clients']['admin-web']['entry'] ?? null;
        if (!is_string($frontendEntry) || !str_ends_with($frontendEntry, '/contribution.ts')) {
            throw BusinessException::invalid(
                'GENERATOR_MODULE_FRONTEND_REQUIRED',
                '目标模块未登记 admin-web 前端贡献，不能生成可装配 CRUD 页面',
            );
        }
        $permissionsRelative = (string) ($manifest['backend']['permissions'] ?? '');
        if ($permissionsRelative === '' || str_starts_with($permissionsRelative, '/') || str_contains($permissionsRelative, '..')) {
            throw new RuntimeException('目标模块权限清单路径无效');
        }
        $permissionsPath = $backendRelative . '/' . $permissionsRelative;
        $routePath = $backendRelative . '/route/app.php';
        return [
            'moduleKey' => (string) $manifest['key'],
            'key' => (string) $manifest['key'],
            'backendRelative' => $backendRelative,
            'frontendRelative' => dirname($frontendEntry),
            'namespace' => $namespace,
            'manifestPath' => $manifestPath,
            'manifestSource' => $manifestSource,
            'manifest' => $manifest,
            'permissionsPath' => $permissionsPath,
            'permissionsSource' => self::readFile($permissionsPath, $root),
            'routePath' => $routePath,
            'routeSource' => self::readFile($routePath, $root),
            'frontendContributionPath' => $frontendEntry,
            'frontendContributionSource' => self::readFile($frontendEntry, $root),
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function columns(array $rawColumns): array
    {
        $columns = [];
        foreach ($rawColumns as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $name = trim((string) ($raw['column_name'] ?? $raw['name'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) {
                throw new RuntimeException('数据表包含不安全的字段名称');
            }
            $type = strtolower((string) ($raw['php_type'] ?? $raw['data_type'] ?? $raw['type'] ?? 'string'));
            $comment = self::plainText((string) ($raw['column_comment'] ?? $raw['comment'] ?? $name));
            $enum = self::columnEnum($raw);
            $columns[] = [
                'name' => $name,
                'columnType' => strtolower((string) ($raw['column_type'] ?? $raw['data_type'] ?? $type)),
                'type' => $type,
                'comment' => $comment !== '' ? $comment : $name,
                'primary' => self::truthy($raw['is_pk'] ?? $raw['primary'] ?? false),
                'required' => self::truthy($raw['is_required'] ?? false) || (($raw['is_nullable'] ?? 'YES') === 'NO'),
                'list' => self::truthy($raw['is_lists'] ?? $raw['is_list'] ?? false),
                'query' => self::truthy($raw['is_query'] ?? false),
                'insert' => self::truthy($raw['is_insert'] ?? false),
                'update' => self::truthy($raw['is_update'] ?? false),
                'length' => self::columnLength($raw),
                'tsType' => $enum === [] ? self::tsType($type) : implode(' | ', array_map(
                    static fn(string $value): string => json_encode($value, JSON_THROW_ON_ERROR),
                    $enum,
                )),
                'openApiType' => self::openApiType($type),
                'enum' => $enum,
                'rule' => self::validationRule($type, self::columnLength($raw)),
            ];
        }
        if ($columns === []) {
            throw new RuntimeException('没有可生成的安全字段');
        }
        return $columns;
    }

    /** @param list<array<string,mixed>> $columns @return array<string,mixed> */
    private static function primaryColumn(array $columns): array
    {
        $primary = array_values(array_filter($columns, static fn(array $column): bool => $column['primary']));
        if (count($primary) > 1) {
            throw new RuntimeException('当前 CRUD 模板不支持复合主键');
        }
        if ($primary !== []) {
            return $primary[0];
        }
        foreach ($columns as $column) {
            if ($column['name'] === 'id') {
                return $column;
            }
        }
        throw new RuntimeException('生成实体必须具有主键');
    }

    private static function primaryType(array $primary): string
    {
        if (in_array($primary['type'], ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
            return 'int';
        }
        if (in_array($primary['type'], ['string', 'varchar', 'char', 'uuid'], true)) {
            return 'string';
        }
        throw new RuntimeException('主键仅支持整数或字符串类型');
    }

    private static function primaryRule(array $primary, string $type): string
    {
        return $type === 'int'
            ? 'require|positiveInteger'
            : 'require|string' . ($primary['length'] > 0 ? '|max:' . $primary['length'] : '');
    }

    /** @param list<array<string,mixed>> $columns @return array{list:list<string>,detail:list<string>} */
    private static function projection(array $columns, array $primary, array $tree, string $softDeleteField): array
    {
        $list = [$primary['name']];
        $detail = [$primary['name']];
        foreach ($columns as $column) {
            if (!self::publicColumn($column['name'], $softDeleteField)) {
                continue;
            }
            if ($column['list']) {
                $list[] = $column['name'];
            }
            if ($column['list'] || $column['query'] || $column['insert'] || $column['update']) {
                $detail[] = $column['name'];
            }
        }
        if ($tree !== []) {
            $list[] = $tree['parent'];
            $list[] = $tree['label'];
        }
        return ['list' => array_values(array_unique($list)), 'detail' => array_values(array_unique($detail))];
    }

    private static function publicColumn(string $name, string $softDeleteField = ''): bool
    {
        if (in_array($name, array_filter(['tenant_id', 'delete_time', $softDeleteField]), true)) {
            return false;
        }
        return preg_match('/(?:password|passwd|secret|token|credential|private_key|api_key|access_key|refresh_key|salt|digest|hash)$/i', $name) !== 1;
    }

    /** @param list<array<string,mixed>> $columns @return array{enabled:bool,field:string} */
    private static function softDeleteConfig(mixed $raw, array $columns, array $primary): array
    {
        if (!is_array($raw)) {
            throw new RuntimeException('软删除配置必须是对象');
        }
        if (array_diff(array_keys($raw), ['enabled', 'field']) !== []) {
            throw new RuntimeException('软删除配置包含未声明字段');
        }
        $enabled = $raw['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new RuntimeException('软删除 enabled 必须是布尔值');
        }
        if (!$enabled) {
            return ['enabled' => false, 'field' => ''];
        }
        $field = trim((string) ($raw['field'] ?? ''));
        if ($field === '' || !in_array($field, array_column($columns, 'name'), true)) {
            throw new RuntimeException('启用软删除时必须声明当前表存在的软删除字段');
        }
        if ($field === $primary['name'] || $field === 'tenant_id') {
            throw new RuntimeException('主键和租户字段不能作为软删除字段');
        }
        return ['enabled' => true, 'field' => $field];
    }

    private static function capabilities(mixed $input, array $columns, array $primary, string $softDeleteField): array
    {
        if (!is_array($input) || array_diff(array_keys($input), ['batchDelete', 'batchUpdateStatus', 'statusField']) !== []) {
            throw new RuntimeException('批量能力配置无效');
        }
        $delete = $input['batchDelete'] ?? false;
        $status = $input['batchUpdateStatus'] ?? false;
        $field = $input['statusField'] ?? '';
        $column = is_string($field) ? (array_column($columns, null, 'name')[$field] ?? null) : null;
        if (!is_bool($delete) || !is_bool($status) || !is_string($field)
            || ($status && (!is_array($column) || $column['type'] !== 'int' || !$column['update'] || !self::publicColumn($field, $softDeleteField)
                || in_array($field, [$primary['name'], 'tenant_id', 'delete_time', $softDeleteField], true)))) {
            throw new RuntimeException('批量状态能力必须声明有效整数状态字段');
        }
        return ['batchDelete' => $delete, 'batchUpdateStatus' => $status, 'statusField' => $status ? $field : ''];
    }

    /** @param list<array<string,mixed>> $columns */
    private static function treeConfig(mixed $rawTree, array $columns, string $templateType): array
    {
        if (is_string($rawTree)) {
            $rawTree = json_decode($rawTree, true) ?? [];
        }
        if (!is_array($rawTree) || $templateType !== 'tree') {
            return [];
        }
        $names = array_column($columns, 'name');
        $parent = (string) ($rawTree['parent_field'] ?? $rawTree['pid_field'] ?? 'pid');
        $label = (string) ($rawTree['label_field'] ?? $rawTree['name_field'] ?? 'name');
        if (!in_array($parent, $names, true) || !in_array($label, $names, true)) {
            throw new RuntimeException('树形配置引用了不存在的字段');
        }
        return ['parent' => $parent, 'label' => $label];
    }

    /** @return list<array<string,mixed>> */
    private static function relations(mixed $rawRelations, array $defaultModule, string $edition, string $root): array
    {
        if (is_string($rawRelations)) {
            try {
                $rawRelations = json_decode($rawRelations, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('关联配置 JSON 无效', 0, $exception);
            }
        }
        if (!is_array($rawRelations)) {
            throw new RuntimeException('关联配置必须是数组');
        }
        $relations = [];
        $used = [];
        foreach ($rawRelations as $raw) {
            if (!is_array($raw)) {
                throw new RuntimeException('关联配置项格式无效');
            }
            $name = self::safeIdentifier((string) ($raw['name'] ?? ''));
            if (isset($used[$name])) {
                throw new RuntimeException('关联名称不能重复');
            }
            $used[$name] = true;
            $model = trim((string) ($raw['model'] ?? ''));
            if (preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/D', $model) !== 1) {
                throw new RuntimeException('关联模型配置不符合生成规范');
            }
            $relatedInput = (string) ($raw['module'] ?? $defaultModule['key']);
            if (str_contains($relatedInput, '.') && $relatedInput !== $defaultModule['key']) {
                throw new RuntimeException('不允许跨模块 ORM 关联；请使用目标模块公开 query 合同');
            }
            $relatedModule = self::module($relatedInput, $root);
            if ($relatedModule['key'] !== $defaultModule['key']) {
                throw new RuntimeException('不允许跨模块 ORM 关联；请使用目标模块公开 query 合同');
            }
            $relatedEdition = strtolower(trim((string) ($raw['target_edition'] ?? '')));
            $relatedOwner = strtolower(trim((string) ($raw['data_owner'] ?? '')));
            if ($relatedEdition !== $edition || $relatedOwner !== 'tenant-orm') {
                throw new RuntimeException('同模块 ORM 关联必须保持 tenant-orm 所有权且 Edition 一致');
            }
            $method = match (strtolower((string) ($raw['relation_type'] ?? $raw['type'] ?? 'belongsTo'))) {
                'hasone', 'has_one' => 'hasOne',
                'hasmany', 'has_many' => 'hasMany',
                'belongsto', 'belongs_to' => 'belongsTo',
                default => throw new RuntimeException('关联类型只允许 belongsTo、hasOne 或 hasMany'),
            };
            $summary = $raw['summary_fields'] ?? [];
            if (!is_array($summary) || count($summary) > 12) {
                throw new RuntimeException('关联摘要字段配置无效');
            }
            $summary = array_values(array_unique(array_map(
                static fn(mixed $field): string => self::safeIdentifier((string) $field),
                $summary,
            )));
            $relatedSoftDeleteField = (string) ($raw['soft_delete_field'] ?? '');
            foreach ($summary as $field) {
                if (!self::publicColumn($field, $relatedSoftDeleteField)) {
                    throw new RuntimeException('关联摘要不能公开租户、删除或秘密字段');
                }
            }
            $summaryTypes = $raw['summary_types'] ?? [];
            if (!is_array($summaryTypes)) {
                throw new RuntimeException('关联摘要类型配置无效');
            }
            foreach ($summary as $field) {
                $fieldType = strtolower((string) ($summaryTypes[$field] ?? ''));
                if ($fieldType === '') {
                    throw new RuntimeException('关联摘要字段必须声明类型');
                }
                $summaryTypes[$field] = $fieldType;
            }
            $relations[] = [
                'name' => $name,
                'method' => $method,
                'namespace' => $relatedModule['namespace'],
                'entity' => $model,
                'localKey' => self::safeIdentifier((string) ($raw['local_key'] ?? 'id')),
                'foreignKey' => self::safeIdentifier((string) ($raw['foreign_key'] ?? 'id')),
                'summaryFields' => $summary,
                'summaryTypes' => $summaryTypes,
                'softDelete' => ($raw['soft_delete_enabled'] ?? false) === true,
            ];
        }
        return $relations;
    }

    private static function renderModel(array $c): string
    {
        $schema = '';
        foreach ($c['columns'] as $column) {
            $type = $column['columnType'];
            // Match ThinkORM PDOConnection::getFieldType(), preserving bigint binding.
            $type = match (true) {
                str_starts_with($type, 'set') => 'set',
                str_starts_with($type, 'enum') => 'enum',
                str_starts_with($type, 'bigint') => 'bigint',
                str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'real') => 'float',
                str_contains($type, 'int') || str_contains($type, 'serial') || str_contains($type, 'bit') => 'int',
                str_contains($type, 'bool') => 'bool',
                str_contains($type, 'json') => 'json',
                str_starts_with($type, 'timestamp') => 'timestamp',
                str_starts_with($type, 'datetime') => 'datetime',
                str_starts_with($type, 'date') => 'date',
                default => 'string',
            };
            $schema .= "        '{$column['name']}' => '{$type}',\n";
        }
        $relations = '';
        foreach ($c['relations'] as $relation) {
            $arguments = $relation['method'] === 'belongsTo'
                ? "'{$relation['localKey']}', '{$relation['foreignKey']}'"
                : "'{$relation['foreignKey']}', '{$relation['localKey']}'";
            $related = '\\' . $relation['namespace'] . 'Model\\' . $relation['entity'];
            $relations .= "\n    public function {$relation['name']}()\n    {\n"
                . "        return \$this->{$relation['method']}({$related}::class, {$arguments});\n    }\n";
        }
        $softImport = $c['softDelete'] ? "\nuse think\\model\\concern\\SoftDelete;" : '';
        $softBody = $c['softDelete'] ? "    use SoftDelete;\n\n    protected \$deleteTime = '{$c['softDeleteField']}';\n\n" : '';
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace {{namespace}}Model;

use app\common\model\TenantOwnedModel;{{softImport}}

class {{entity}} extends TenantOwnedModel
{
{{softBody}}    {{tableDeclaration}}
    protected $pk = '{{primary}}';

    // Keep aligned with this module's migrations.
    protected $schema = [
{{schema}}    ];
{{relationMethods}}}
PHP, $c + compact('softImport', 'softBody', 'schema') + ['relationMethods' => $relations]);
    }

    private static function renderController(array $c): string
    {
        $enabledActions = array_filter(array_intersect_key($c['capabilities'], array_flip(['batchDelete', 'batchUpdateStatus'])));
        $actionDeclaration = $enabledActions === [] ? '' : '    protected const CRUD_ACTIONS = ' . var_export($enabledActions, true) . ";\n";
        $softDeleteDeclaration = $c['softDelete'] ? "    protected const CRUD_SOFT_DELETE = true;\n" : '';
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace {{namespace}}Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\traits\CrudTrait;
use {{namespace}}Service\{{entity}}Service;
use {{namespace}}Validation\{{entity}}Validate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\App;

final class {{entity}}Controller extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = {{entity}}Validate::class;
    protected const CRUD_VALIDATE_LISTS = true;
{{actionDeclaration}}{{softDeleteDeclaration}}    protected const CRUD_PRIMARY_KEY = '{{primary}}';
    protected const CRUD_PRIMARY_KEY_TYPE = '{{primaryType}}';
    protected const CRUD_INPUT_FIELDS = {{inputFields}};
    protected const CRUD_WRITABLE_FIELDS = {{writableFields}};

    public function __construct(App $app, protected readonly {{entity}}Service $crud)
    {
        parent::__construct($app);
    }

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }
}
PHP, $c + self::controllerFieldPolicies($c) + compact('softDeleteDeclaration', 'actionDeclaration'));
    }

    private static function renderService(array $c): string
    {
        $policies = self::fieldPolicies($c);
        $queryCode = '';
        foreach ($c['columns'] as $column) {
            if (!$column['query'] || !self::publicColumn($column['name'])) {
                continue;
            }
            $operator = $column['enum'] === [] && in_array($column['type'], ['string', 'varchar', 'char', 'text'], true)
                ? 'whereLike'
                : 'where';
            $value = $operator === 'whereLike' ? "'%' . trim((string)\$params['{$column['name']}']) . '%'" : "\$params['{$column['name']}']";
            $queryCode .= "        if (isset(\$params['{$column['name']}']) && \$params['{$column['name']}'] !== '') {\n"
                . "            \$query->{$operator}('{$column['name']}', {$value});\n        }\n";
        }
        $relationNames = array_values(array_map(
            static fn(array $relation): string => $relation['name'],
            array_filter($c['relations'], static fn(array $relation): bool => $relation['summaryFields'] !== []),
        ));
        $withCode = $relationNames === [] ? '' : '->with(' . var_export($relationNames, true) . ')';
        if ($c['tree'] !== []) {
            $listBody = "        \$rows = \$query->field(self::LIST_FIELDS){$withCode}->order(['{$c['primary']}' => 'desc'])->limit(2000)->select()\n"
                . "            ->map(fn(\$item): array => \$this->project(\$item, self::LIST_FIELDS))->toArray();\n"
                . "        return linear_to_tree(\$rows, 'children', '{$c['primary']}', '{$c['tree']['parent']}');";
        } else {
            $listBody = "        \$page = PaginationInput::from(\$params)->result(\$query->field(self::LIST_FIELDS){$withCode}->order(['{$c['primary']}' => 'desc']));\n"
                . "        return \$page->map(fn(\$item): array => \$this->project(\$item, self::LIST_FIELDS));";
        }
        $softImport = $c['softDelete'] ? "\nuse think\\db\\exception\\PDOException;" : '';
        $baseService = $c['tree'] !== [] ? 'AbstractTreeCrudService' : 'AbstractCrudService';
        $parentField = $c['tree'] !== [] ? "    protected function parentField(): string { return '{$c['tree']['parent']}'; }\n    protected function structureQuery(TenantContext \$context): BaseQuery { return {$c['entity']}::where('tenant_id', \$context->tenantId); }\n" : '';
        $softMethods = self::renderSoftDeleteService($c, $queryCode, $listBody);
        $withStatement = $relationNames === [] ? '' : '        $query->with(' . var_export($relationNames, true) . ");\n";
        $statusField = $c['capabilities']['batchUpdateStatus'] ? "    protected function statusField(): ?string { return '{$c['capabilities']['statusField']}'; }\n" : '';
        $treeError = $c['tree'] === [] ? '' : "    protected function buildTree(array \$rows): array { return linear_to_tree(\$rows, 'children', '{$c['primary']}', '{$c['tree']['parent']}'); }\n    protected function treeHasChildrenError(): BusinessException\n    {\n        return BusinessException::conflict('{$c['errorPrefix']}_HAS_CHILDREN', '请先删除下级节点');\n    }\n\n";
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace {{namespace}}Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\http\PageResult;
use app\common\support\PaginationInput;
use app\common\services\{{baseService}};
use {{namespace}}Model\{{entity}};
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;
use think\db\BaseQuery;
use think\Model;
{{softImport}}

class {{entity}}Service extends {{baseService}}
{
    private const INSERT_FIELDS = {{insertFields}};
    private const UPDATE_FIELDS = {{updateFields}};
    private const LIST_FIELDS = {{listFieldsExport}};
    private const DETAIL_FIELDS = {{detailFieldsExport}};
    private const RELATION_SUMMARIES = {{relationSummaries}};

    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly AdminAuthorizationQuery $authorization,
    ) {}

    protected function primaryKey(): string { return '{{primary}}'; }
    protected function listFields(): array { return self::LIST_FIELDS; }
    protected function detailFields(): array { return self::DETAIL_FIELDS; }
    protected function insertFields(): array { return self::INSERT_FIELDS; }
    protected function updateFields(): array { return self::UPDATE_FIELDS; }
{{diffMethod}}{{parentField}}{{statusField}}    protected function query(TenantContext $context): BaseQuery
    {
        return {{entity}}::where('tenant_id', $context->tenantId);
    }

    protected function newModel(TenantContext $context): Model
    {
        return new {{entity}}();
    }

    protected function configureListQuery(TenantContext $context, BaseQuery $query, array $params): void
    {
{{queryCode}}{{withStatement}}    }

    protected function configureDetailQuery(TenantContext $context, BaseQuery $query): void
    {
{{withStatement}}    }

    protected function resourceError(string $operation): BusinessException
    {
        return match ($operation) {
            'notFound' => BusinessException::notFound('{{errorPrefix}}_NOT_FOUND', '{{title}}不存在'),
            'delete' => BusinessException::invalid('{{errorPrefix}}_DELETE_FAILED', '删除失败，请稍后重试'),
            'statusUnsupported' => BusinessException::invalid('{{errorPrefix}}_STATUS_UNSUPPORTED', '该资源未声明统一状态字段'),
            default => BusinessException::invalid('{{errorPrefix}}_SAVE_FAILED', '保存失败，请稍后重试'),
        };
    }

{{treeError}}{{softMethods}}

    public function updateStatus(TenantContext $context, int|string $id, int $status): bool
    {
        throw $this->resourceError('statusUnsupported');
    }

    /** 公开输出只取声明字段；关联只返回明确摘要。 */
    protected function project(Model|array $record, array $fields): array
    {
        $data = $record instanceof Model ? $record->toArray() : $record;
        $result = array_intersect_key($data, array_flip($fields));
        foreach (self::RELATION_SUMMARIES as $name => $summaryFields) {
            if (!array_key_exists($name, $data) || $data[$name] === null) continue;
            $related = $data[$name];
            $result[$name] = array_is_list($related)
                ? array_map(static fn(array $item): array => array_intersect_key($item, array_flip($summaryFields)), $related)
                : array_intersect_key((array)$related, array_flip($summaryFields));
        }
        return $result;
    }

    /** 非 HTTP 调用也必须复核当前可信身份和固定动作权限。 */
    protected function assertPermission(TenantContext $context, string $permission): void
    {
        if (!str_contains($permission, '.')) $permission = '{{permissionPrefix}}.' . $permission;
        try {
            $current = $this->executionContext->tenantAdmin();
            $actor = AdminPrincipal::fromArray($this->executionContext->tenantAdminPrincipal());
        } catch (\Throwable) {
            throw BusinessException::forbidden('{{errorPrefix}}_PERMISSION_DENIED', '无权执行该操作');
        }
        if ($current->tenantId !== $context->tenantId
            || $current->accountId !== $context->accountId
            || $current->memberId !== $context->memberId
            || $current->authorizationRevision !== $context->authorizationRevision
            || !$this->authorization->decide($context, $actor, $permission)->allowed
        ) {
            throw BusinessException::forbidden('{{errorPrefix}}_PERMISSION_DENIED', '无权执行该操作');
        }
    }
}
PHP, $c + $policies + [
            'queryCode' => $queryCode,
            'listBody' => $listBody,
            'diffMethod' => $policies['diffFieldsArray'] === [] ? ''
                : '    protected function diffFields(): array { return '
                    . var_export($policies['diffFieldsArray'], true) . "; }\n",
            'listFieldsExport' => var_export($c['listFields'], true),
            'detailFieldsExport' => var_export($c['detailFields'], true),
            'relationSummaries' => var_export(array_column($c['relations'], 'summaryFields', 'name'), true),
            'softImport' => $softImport,
            'softMethods' => $softMethods,
            'baseService' => $baseService,
            'parentField' => $parentField,
            'withStatement' => $withStatement,
            'treeError' => $treeError,
            'statusField' => $statusField,
        ]);
    }

    private static function renderSoftDeleteService(array $c, string $queryCode, string $listBody): string
    {
        if (!$c['softDelete']) {
            return '';
        }

        $relationNames = array_values(array_map(
            static fn(array $relation): string => $relation['name'],
            array_filter($c['relations'], static fn(array $relation): bool => $relation['summaryFields'] !== []),
        ));
        $withCode = $relationNames === [] ? '' : '->with(' . var_export($relationNames, true) . ')';

        $restoreRelationGuards = '';
        $purgeRelationGuards = '';
        foreach ($c['relations'] as $relation) {
            $related = '\\' . $relation['namespace'] . 'Model\\' . $relation['entity'];
            if ($relation['method'] === 'belongsTo') {
                $restoreRelationGuards .= "        \$relationValue = \$model->getAttr('{$relation['localKey']}');\n"
                    . "        if (\$relationValue !== null && \$relationValue !== ''\n"
                    . "            && {$related}::where('{$relation['foreignKey']}', \$relationValue)->lock(true)->findOrEmpty()->isEmpty()\n"
                    . "        ) {\n"
                    . "            throw BusinessException::conflict('{$c['errorPrefix']}_RESTORE_RELATION_CONFLICT', '恢复所需的关联对象不存在或已删除');\n"
                    . "        }\n";
                continue;
            }
            $targetQuery = $relation['softDelete'] ? 'withTrashed()' : 'where([])';
            $purgeRelationGuards .= "        if (!{$related}::{$targetQuery}->where('{$relation['foreignKey']}', \$model->getAttr('{$relation['localKey']}'))\n"
                . "            ->lock(true)->findOrEmpty()->isEmpty()\n"
                . "        ) {\n"
                . "            throw BusinessException::conflict('{$c['errorPrefix']}_PURGE_RELATION_CONFLICT', '对象仍有关联记录，不能永久删除');\n"
                . "        }\n";
        }

        $normalizeIds = $c['primaryType'] === 'int'
            ? "            if (filter_var(\$id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {\n"
                . "                throw BusinessException::invalid('{$c['errorPrefix']}_BATCH_IDS_INVALID', '操作对象主键格式错误');\n"
                . "            }\n            \$normalized[] = (int)\$id;"
            : "            if (!is_string(\$id) && !is_int(\$id)) {\n"
                . "                throw BusinessException::invalid('{$c['errorPrefix']}_BATCH_IDS_INVALID', '操作对象主键格式错误');\n"
                . "            }\n            \$value = trim((string)\$id);\n"
                . "            if (\$value === ''" . ($c['primaryLength'] > 0 ? " || mb_strlen(\$value) > {$c['primaryLength']}" : '') . ") {\n"
                . "                throw BusinessException::invalid('{$c['errorPrefix']}_BATCH_IDS_INVALID', '操作对象主键格式错误');\n"
                . "            }\n            \$normalized[] = \$value;";

        return self::replace(<<<'PHP'
    public function recycleLists(TenantContext $context, array $params): array|PageResult
    {
        $this->assertPermission($context, '{{permissionPrefix}}.recycle.list');
        $query = {{entity}}::onlyTrashed();
{{queryCode}}{{listBody}}
    }

    public function recycleDetail(TenantContext $context, int|string $id): array
    {
        $this->assertPermission($context, '{{permissionPrefix}}.recycle.detail');
        $model = {{entity}}::onlyTrashed()->where('{{primary}}', $id){{withCode}}->findOrEmpty();
        if ($model->isEmpty()) {
            throw BusinessException::notFound('{{errorPrefix}}_NOT_FOUND', '{{title}}不存在');
        }
        return $this->project($model, self::DETAIL_FIELDS);
    }

    public function restore(TenantContext $context, array $ids): array
    {
        $this->assertPermission($context, '{{permissionPrefix}}.restore');
        return $this->batch($ids, 'restored', function (array $models, array $normalizedIds): array {
            $outcomes = [];
            foreach ($normalizedIds as $id) {
                $model = $models[$id] ?? null;
                if (!$model instanceof {{entity}}) {
                    throw BusinessException::notFound('{{errorPrefix}}_NOT_FOUND', '{{title}}不存在');
                }
                if (!$model->trashed()) {
                    $outcomes[$id] = 'already_active';
                    continue;
                }
                $this->assertRestoreRelations($model);
                $this->assertRestoreBusinessState($model);
                try {
                    if ($model->restore() === false) throw new \LogicException('{{errorPrefix}}_RESTORE_FAILED');
                } catch (PDOException $exception) {
                    if (!$this->isUniqueConflict($exception)) throw $exception;
                    throw BusinessException::conflict(
                        '{{errorPrefix}}_RESTORE_UNIQUE_CONFLICT',
                        '已有未删除记录占用唯一值，不能恢复',
                    );
                }
                $outcomes[$id] = 'restored';
            }
            return $outcomes;
        });
    }

    public function purge(TenantContext $context, array $ids): array
    {
        $this->assertPermission($context, '{{permissionPrefix}}.purge');
        return $this->batch($ids, 'purged', function (array $models, array $normalizedIds): array {
            $outcomes = [];
            foreach ($normalizedIds as $id) {
                $model = $models[$id] ?? null;
                if (!$model instanceof {{entity}}) {
                    throw BusinessException::notFound('{{errorPrefix}}_NOT_FOUND', '{{title}}不存在');
                }
                if (!$model->trashed()) {
                    throw BusinessException::conflict('{{errorPrefix}}_PURGE_REQUIRES_TRASHED', '仅回收站记录可永久删除');
                }
                $this->assertPurgeRelations($model);
                if ($model->force(true)->delete() === false) throw new \LogicException('{{errorPrefix}}_PURGE_FAILED');
                $outcomes[$id] = 'purged';
            }
            return $outcomes;
        });
    }

    private function assertRestoreRelations({{entity}} $model): void
    {
{{restoreRelationGuards}}    }

    /** 通用生成器不定义业务状态迁移；恢复保留原状态，不改写任何业务字段。 */
    private function assertRestoreBusinessState({{entity}} $model): void
    {
        $model->getData();
    }

    private function assertPurgeRelations({{entity}} $model): void
    {
{{purgeRelationGuards}}    }

    private function isUniqueConflict(PDOException $exception): bool
    {
        $error = $exception->getData()['PDO Error Info'] ?? [];
        return (string)($error['SQLSTATE'] ?? $exception->getCode()) === '23000'
            && (int)($error['Driver Error Code'] ?? 0) === 1062;
    }

    private function batch(array $ids, string $successKey, callable $operation): array
    {
        $ids = $this->normalizeBatchIds($ids);
        $sortedIds = $ids;
        sort($sortedIds);
        $result = ['requested' => $ids, $successKey => [], 'already_active' => [], 'failed' => []];
        $outcomes = Db::transaction(function () use ($sortedIds, $ids, $operation): array {
            $models = [];
            foreach ({{entity}}::withTrashed()->whereIn('{{primary}}', $sortedIds)->order('{{primary}}', 'asc')->lock(true)->select() as $model) {
                $models[$model->getAttr('{{primary}}')] = $model;
            }
            return $operation($models, $ids);
        });
        foreach ($outcomes as $id => $bucket) {
            $result[$bucket][] = $id;
        }
        return $result;
    }

    private function normalizeBatchIds(array $ids): array
    {
        if ($ids === [] || count($ids) > 100) {
            throw BusinessException::invalid('{{errorPrefix}}_BATCH_IDS_INVALID', '操作对象数量须在 1 到 100 之间');
        }
        $normalized = [];
        foreach ($ids as $id) {
{{normalizeIds}}
        }
        return array_values(array_unique($normalized, SORT_REGULAR));
    }
PHP, $c + compact(
            'queryCode',
            'listBody',
            'restoreRelationGuards',
            'purgeRelationGuards',
            'normalizeIds',
            'withCode',
        ));
    }

    private static function renderValidate(array $c): string
    {
        $policies = self::fieldPolicies($c);
        $rules = [$c['primary'] => $c['primaryRule']];
        $messages = [];
        $relevant = array_unique([...$policies['insertFieldsArray'], ...$policies['updateFieldsArray'], ...$policies['listFieldsArray']]);
        foreach ($c['columns'] as $column) {
            if (!in_array($column['name'], $relevant, true) || $column['name'] === $c['primary']) {
                continue;
            }
            $parts = [];
            if ($column['required'] && $column['insert']) {
                $parts[] = 'require';
            }
            if ($column['rule'] !== '') {
                $parts = [...$parts, ...explode('|', $column['rule'])];
            }
            if ($parts !== []) {
                $parts = array_values(array_unique($parts));
                $rules[$column['name']] = $column['enum'] === []
                    ? implode('|', $parts)
                    : [...$parts, 'in' => $column['enum']];
            }
            if ($column['required'] && $column['insert']) {
                $messages[$column['name'] . '.require'] = $column['comment'] . '不能为空';
            }
        }
        $hasBatch = $c['capabilities']['batchDelete'] || $c['capabilities']['batchUpdateStatus'];
        if ($c['softDelete'] || $hasBatch) {
            $rules['ids'] = 'array|checkIds';
        }
        $editRules = '';
        foreach ($c['columns'] as $column) {
            $name = $column['name'];
            if ($column['required'] && $column['insert'] && in_array($name, $policies['updateFieldsArray'], true)) {
                $editRules .= "\n            ->remove('{$name}', 'require')"
                    . "->append('{$name}', 'requirePresentValue')";
                $messages[$name . '.requirePresentValue'] = $column['comment'] . '不能为空';
            }
        }
        $editScene = $editRules === '' ? '' : self::replace(<<<'PHP'

    public function sceneEdit(): self
    {
        return $this->only({{editFields}}){{editRules}};
    }

    protected function requirePresentValue(mixed $value, mixed $rule, array $data, string $field): bool
    {
        return !array_key_exists($field, $data) || $this->is($value, 'require');
    }
PHP, [
            'editFields' => var_export([$c['primary'], ...$policies['updateFieldsArray']], true),
            'editRules' => $editRules,
        ]);
        $listRemovals = '';
        foreach ($policies['listFieldsArray'] as $field) {
            if (isset($rules[$field]) && (
                (is_string($rules[$field]) && str_contains($rules[$field], 'require'))
                || (is_array($rules[$field]) && in_array('require', $rules[$field], true))
            )) {
                $listRemovals .= "\n            ->remove('{$field}', 'require')";
            }
        }
        $softScenes = $c['softDelete']
            ? "        'recycle' => {$policies['listFields']},\n"
                . "        'recycleDetail' => ['{$c['primary']}'],\n"
                . "        'restore' => ['{$c['primary']}', 'ids'],\n"
                . "        'purge' => ['{$c['primary']}', 'ids'],\n"
            : '';
        $idCheck = $c['primaryType'] === 'int'
            ? "            if (!\$this->positiveInteger(\$id)) return '操作对象主键格式错误';"
            : "            if ((!is_string(\$id) && !is_int(\$id)) || trim((string)\$id) === ''"
                . ($c['primaryLength'] > 0 ? " || mb_strlen(trim((string)\$id)) > {$c['primaryLength']}" : '')
                . ") return '操作对象主键格式错误';";
        $softSceneMethods = ($c['softDelete'] || $hasBatch) ? self::replace(<<<'PHP'

    public function sceneRecycle(): self
    {
        return $this->sceneLists();
    }

    public function sceneRecycleDetail(): self
    {
        return $this->only(['{{primary}}']);
    }

    public function sceneRestore(): self
    {
        return $this->batchIdsScene();
    }

    public function scenePurge(): self
    {
        return $this->batchIdsScene();
    }

    protected function checkIds(mixed $value): bool|string
    {
        if (!is_array($value) || $value === [] || count($value) > 100) return '操作对象数量须在 1 到 100 之间';
        foreach ($value as $id) {
{{idCheck}}
        }
        return true;
    }

    private function batchIdsScene(): self
    {
        return $this->only(['{{primary}}', 'ids'])
            ->replace('{{primary}}', 'requireWithout:ids|{{primaryBatchRule}}')
            ->replace('ids', 'requireWithout:{{primary}}|array|checkIds');
    }
PHP, $c + [
            'idCheck' => $idCheck,
            'primaryBatchRule' => preg_replace('/^require\|/', '', $c['primaryRule']) ?? $c['primaryRule'],
        ]) : '';
        $batchScenes = '';
        if ($c['capabilities']['batchDelete']) {
            $batchScenes .= "    public function sceneBatchDelete(): self { return \$this->only(['ids'])->replace('ids', 'require|array|checkIds'); }\n";
        }
        if ($c['capabilities']['batchUpdateStatus']) {
            $rules['status'] = 'require|checkStatus';
            $statusColumn = array_column($c['columns'], null, 'name')[$c['capabilities']['statusField']];
            $allowedStatus = $statusColumn['enum'];
            $statusCheck = $allowedStatus === [] ? 'is_int($value)' : 'is_int($value) && in_array((string) $value, ' . var_export(array_map('strval', $allowedStatus), true) . ', true)';
            $batchScenes .= "    public function sceneBatchUpdateStatus(): self { return \$this->only(['ids', 'status'])->replace('ids', 'require|array|checkIds'); }\n"
                . "    protected function checkStatus(mixed \$value): bool|string { return (" . $statusCheck . ") ?: '状态值无效'; }\n";
        }
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace {{namespace}}Validation;

use app\common\validate\ListsValidate;

final class {{entity}}Validate extends ListsValidate
{
    protected $rule = [
        ...{{rules}},
        'page_no' => self::PAGE_NUMBER_RULE,
        'page_size' => self::BOUNDED_PAGE_SIZE_RULE,
    ];
    protected $message = {{messages}};
    protected $scene = [
        'add' => {{insertFields}},
        'edit' => {{editSceneFields}},
        'detail' => ['{{primary}}'],
        'delete' => ['{{primary}}'],
        'lists' => {{listFields}},
{{softScenes}}    ];

    public function sceneLists(): self
    {
        return $this->only({{listFields}}){{listRemovals}};
    }
{{editScene}}{{softSceneMethods}}{{batchScenes}}}
PHP, array_merge($c, $policies, [
            'rules' => var_export($rules, true),
            'messages' => var_export($messages, true),
            'editScene' => $editScene,
            'editSceneFields' => var_export([$c['primary'], ...$policies['updateFieldsArray']], true),
            'listRemovals' => $listRemovals,
            'softScenes' => $softScenes,
            'softSceneMethods' => $softSceneMethods,
            'batchScenes' => $batchScenes,
        ]));
    }

    private static function renderApi(array $c): string
    {
        $policies = self::fieldPolicies($c);
        $softTypes = $c['softDelete'] ? self::replace(<<<'TS'

export interface {{entity}}BatchResult {
  requested: {{primaryTsType}}[];
  restored?: {{primaryTsType}}[];
  purged?: {{primaryTsType}}[];
  already_active: {{primaryTsType}}[];
  failed: Array<{ {{primary}}: {{primaryTsType}}; code: string; message: string }>;
}
TS, $c) : '';
        $softFunctions = $c['softDelete'] ? self::replace(<<<'TS'

export function get{{entity}}RecycleList(params: {{entity}}ListParams = {}, signal?: AbortSignal) {
  return axios.get<{{entity}}ListResult>('/adminapi/{{permissionPrefix}}.recycle.list', { params, signal });
}

export function get{{entity}}RecycleDetail({{primary}}: {{primaryTsType}}) {
  return axios.get<{{entity}}Detail>('/adminapi/{{permissionPrefix}}.recycle.detail', { params: { {{primary}} } });
}

export function restore{{entity}}(ids: {{primaryTsType}}[], signal?: AbortSignal) {
  return axios.post<{{entity}}BatchResult>('/adminapi/{{permissionPrefix}}.restore', { ids }, { signal });
}

export function purge{{entity}}(ids: {{primaryTsType}}[], signal?: AbortSignal) {
  return axios.post<{{entity}}BatchResult>('/adminapi/{{permissionPrefix}}.purge', { ids }, { signal });
}
TS, $c) : '';
        $batchFunctions = '';
        foreach (['batchDelete', 'batchUpdateStatus'] as $action) {
            if (!$c['capabilities'][$action]) continue;
            $statusArg = $action === 'batchUpdateStatus' ? ', status: number' : '';
            $statusBody = $action === 'batchUpdateStatus' ? ', status' : '';
            $batchFunctions .= "\nexport function {$action}{$c['entity']}(ids: {$c['primaryTsType']}[]{$statusArg}, signal?: AbortSignal) {\n"
                . "  return axios.post('/adminapi/{$c['permissionPrefix']}.{$action}', { ids{$statusBody} }, { signal });\n}\n";
        }
        $listResultType = $c['tree'] === [] ? "export interface {$c['entity']}ListResult {\n  lists: {$c['entity']}ListRecord[];\n  count: number;\n  pageNo: number;\n  pageSize: number;\n}" : "export type {$c['entity']}ListResult = {$c['entity']}ListRecord[];";
        return self::replace(<<<'TS'
import axios from 'axios';

export interface {{entity}}ListRecord {
{{listTypeFields}}}

export interface {{entity}}Detail {
{{detailTypeFields}}}

export interface {{entity}}CreateInput {
{{createTypeFields}}}

export interface {{entity}}UpdateInput {
{{updateTypeFields}}}

{{listResultType}}

export interface {{entity}}ListParams {
{{listParamFields}}}
{{softTypes}}

export function get{{entity}}List(params: {{entity}}ListParams = {}, signal?: AbortSignal) {
  return axios.get<{{entity}}ListResult>('/adminapi/{{permissionPrefix}}.list', { params, signal });
}

export function get{{entity}}Detail({{primary}}: {{primaryTsType}}, signal?: AbortSignal) {
  return axios.get<{{entity}}Detail>('/adminapi/{{permissionPrefix}}.detail', { params: { {{primary}} }, signal });
}

export function add{{entity}}(data: {{entity}}CreateInput, signal?: AbortSignal) {
  return axios.post('/adminapi/{{permissionPrefix}}.add', data, { signal });
}

export function edit{{entity}}(data: {{entity}}UpdateInput, signal?: AbortSignal) {
  return axios.post('/adminapi/{{permissionPrefix}}.edit', data, { signal });
}

export function delete{{entity}}({{primary}}: {{primaryTsType}}, signal?: AbortSignal) {
  return axios.post('/adminapi/{{permissionPrefix}}.delete', { {{primary}} }, { signal });
}
{{softFunctions}}{{batchFunctions}}
TS, $c + [
            'listTypeFields' => self::typescriptFields($c, $c['listFields']) . ($c['tree'] !== [] ? "  children?: {$c['entity']}ListRecord[];\n" : ''),
            'detailTypeFields' => self::typescriptFields($c, $c['detailFields'])
                . ($policies['diffFieldsArray'] === [] ? '' : <<<'TS'
  audit_history?: Array<{
    id: string;
    occurred_at: string;
    before: Record<string, unknown>;
    after: Record<string, unknown>;
  }>;

TS
                ),
            'createTypeFields' => self::typescriptFields($c, $policies['insertFieldsArray'], false),
            'updateTypeFields' => self::typescriptFields($c, [$c['primary'], ...$policies['updateFieldsArray']], false, true),
            'listParamFields' => self::typescriptQueryFields($c, $policies['listFieldsArray']),
            'softTypes' => $softTypes,
            'softFunctions' => $softFunctions,
            'batchFunctions' => $batchFunctions,
            'listResultType' => $listResultType,
        ]);
    }

    /** Builds ordinary form controls only from the generated request whitelist. */
    private static function viewCrudControls(array $c): array
    {
        $policies = self::fieldPolicies($c);
        $writable = array_values(array_unique([...$policies['insertFieldsArray'], ...$policies['updateFieldsArray']]));
        $queryFields = array_values(array_diff($policies['listFieldsArray'], ['page_no', 'page_size']));
        $columns = array_column($c['columns'], null, 'name');
        $formDefaults = $editDefaults = $formItems = $detailItems = $parsers = $createFields = $updateFields = $searchItems = $filterDefaults = $filterFields = $filterParams = '';
        foreach ($c['detailFields'] as $name) {
            $label = htmlspecialchars($columns[$name]['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $detailItems .= "        <ElDescriptionsItem label=\"{$label}\">{{ detailRecord?.{$name} }}</ElDescriptionsItem>\n";
        }
        foreach (array_values(array_unique([...$writable, ...$queryFields])) as $name) {
            $column = $columns[$name];
            $type = $column['tsType'];
            $label = htmlspecialchars($column['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $enum = $column['enum'];
            if ($enum !== []) {
                $cases = '';
                foreach ($enum as $value) {
                    $literal = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $cases .= "      case {$literal}: return {$literal};\n";
                }
                $parser = "  const parse_{$name} = (value: string): {$type} => {\n    switch (value) {\n{$cases}      default: throw new Error('{$label}值无效');\n    }\n  };\n";
                $input = '<ElSelect v-model="{{binding}}" clearable><ElOption v-for="option in {{options}}" :key="option" :label="option" :value="option" /></ElSelect>';
                $input = str_replace('{{options}}', htmlspecialchars(json_encode($enum, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'), $input);
            } elseif (in_array($column['type'], ['array', 'json'], true)) {
                $parser = "  const parse_{$name} = (value: string): {$type} => {\n    const parsed: unknown = JSON.parse(value);\n    if (typeof parsed !== 'object' || parsed === null) throw new Error('{$label}必须是 JSON 对象或数组');\n    if (Array.isArray(parsed)) return parsed;\n    return Object.fromEntries(Object.entries(parsed));\n  };\n";
                $input = '<ElInput v-model="{{binding}}" type="textarea" />';
            } elseif ($type === 'number') {
                $integer = in_array($column['type'], ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true);
                $check = $integer ? '!Number.isSafeInteger(result)' : '!Number.isFinite(result)';
                $parser = "  const parse_{$name} = (value: string): number => {\n    const result = Number(value);\n    if (value.trim() === '' || {$check}) throw new Error('{$label}必须是有效数字');\n    return result;\n  };\n";
                $input = '<ElInput v-model="{{binding}}" type="number" />';
            } else {
                $parser = '';
                $input = '<ElInput v-model="{{binding}}" />';
            }
            $parsers .= $parser;
            if (in_array($name, $writable, true)) {
                $default = "''";
                $formDefaults .= "      {$name}: {$default},\n";
                $public = self::publicColumn($name, $c['softDeleteField']);
                $editDefaults .= $public && in_array($name, $c['detailFields'], true)
                    ? (in_array($column['type'], ['array', 'json'], true)
                        ? "      {$name}: record.{$name} == null ? {$default} : JSON.stringify(record.{$name}),\n"
                        : "      {$name}: record.{$name} == null ? {$default} : String(record.{$name}),\n")
                    : "      {$name}: {$default},\n";
                $mode = [];
                if ($column['insert']) $mode[] = "mode === 'create'";
                if ($column['update']) $mode[] = "mode === 'edit'";
                $binding = str_replace('{{binding}}', "form.{$name}", $input);
                if (!$public && $column['type'] === 'string' && $enum === []) {
                    $binding = '<ElInput v-model="form.' . $name . '" type="password" show-password />';
                }
                $required = $column['required'] && $column['insert'] ? ' :rules="mode === \'create\' ? [{ required: true, message: \'请填写' . $label . '\' }] : []"' : '';
                $formItems .= "        <ElFormItem v-if=\"" . implode(' || ', $mode) . "\" label=\"{$label}\" prop=\"{$name}\"{$required}>{$binding}</ElFormItem>\n";
                $value = $parser === '' ? "form.{$name}" : "parse_{$name}(form.{$name})";
                if ($column['insert']) {
                    $createFields .= !$column['required']
                        ? "        ...(form.{$name} === '' ? {} : { {$name}: {$value} }),\n"
                        : "        {$name}: {$value},\n";
                }
                if ($column['update']) {
                    $updateFields .= (!$public || $type === 'number' || $enum !== [] || in_array($column['type'], ['array', 'json'], true))
                        ? "        ...(form.{$name} === '' ? {} : { {$name}: {$value} }),\n"
                        : "        {$name}: {$value},\n";
                }
            }
            if (in_array($name, $queryFields, true)) {
                $filterDefaults .= "{$name}: '', ";
                $filterFields .= "{$name}: string; ";
                $binding = str_replace('{{binding}}', "searchForm.{$name}", $input);
                $searchItems .= "        <ElFormItem label=\"{$label}\">{$binding}</ElFormItem>\n";
                $value = $parser === '' ? "filters.{$name}" : "parse_{$name}(filters.{$name})";
                $filterParams .= "        ...(filters.{$name} === '' ? {} : { {$name}: {$value} }),\n";
            }
        }
        return compact('formDefaults', 'editDefaults', 'formItems', 'detailItems', 'parsers', 'createFields', 'updateFields', 'searchItems', 'filterDefaults', 'filterFields', 'filterParams');
    }

    private static function renderView(array $c): string
    {
        $controls = self::viewCrudControls($c);
        $historyProp = self::fieldPolicies($c)['diffFieldsArray'] === []
            ? '' : "\n" . '      :audit-history="detailRecord?.audit_history"';
        $visible = array_values(array_filter($c['columns'], static fn(array $column): bool => in_array($column['name'], $c['listFields'], true)));
        $columns = '';
        $inlineFields = '';
        $updateFields = self::fieldPolicies($c)['updateFieldsArray'];
        foreach (array_slice($visible, 0, 8) as $column) {
            $name = $column['name'];
            $editable = in_array($name, $updateFields, true)
                && !in_array($column['type'], ['array', 'json'], true);
            $flag = $editable ? ', editable: true' : '';
            $label = json_encode($column['comment'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $columns .= "    { label: {$label}, key: '{$name}'{$flag} },\n";
            if ($editable) {
                $value = "values.{$name}";
                $parsed = $column['tsType'] === 'number' || $column['enum'] !== []
                    ? "parse_{$name}({$value})" : $value;
                $inlineFields .= "      ...({$value} === undefined ? {} : { {$name}: {$parsed} }),\n";
            }
        }
        $inlineProps = $inlineFields === '' ? '' : "\n" . '      :can-edit="canEditRow" :save-edit="saveRowEdit"';
        $inlineHandlers = $inlineFields === '' ? '' : self::replace(<<<'TS'
  const canEditRow = () => pageCurrent.value && {{normalList}}can('edit');
  const saveRowEdit = async (row: {{entity}}ListRecord, values: Record<string, string>, signal: AbortSignal) => {
    if (!canEditRow()) throw new Error('无权编辑');
    const input: {{entity}}UpdateInput = {
      {{primary}}: readPrimaryKey(row),
{{inlineFields}}    };
    await edit{{entity}}(input, signal);
    if (!signal.aborted && canEditRow()) await fetchData(pagination.page);
  };

TS, $c + [
            'normalList' => $c['softDelete'] ? '!searchForm.value.recycle && ' : '',
            'inlineFields' => $inlineFields,
        ]);
        $recycleToggle = $c['softDelete'] ? self::replace(<<<'VUE'
    <ElButton
      v-permission="['{{permissionPrefix}}.recycle.list']"
      :disabled="actionLoading"
      @click="toggleRecycle"
    >
      {{ showRecycle ? '返回普通列表' : '回收站' }}
    </ElButton>
    <ElAlert v-if="actionError" :title="actionError" type="error" :closable="false" />
VUE, $c) : '';
        $actionSlot = $c['softDelete'] ? self::replace(<<<'VUE'
      <ElTableColumn v-if="showRecycle" label="操作" :width="180">
        <template #default="{ row }">
          <ElButton
            v-permission="['{{permissionPrefix}}.restore']"
            link
            type="primary"
            :disabled="actionLoading"
            @click="handleRestore(row)"
          >恢复</ElButton>
          <ElPopconfirm title="永久删除后不可恢复，确定继续？" @confirm="handlePurge(row)">
            <template #reference>
              <ElButton
                v-permission="['{{permissionPrefix}}.purge']"
                link
                type="danger"
                :disabled="actionLoading"
              >永久删除</ElButton>
            </template>
          </ElPopconfirm>
        </template>
      </ElTableColumn>
VUE, $c) : '';
        $apiImports = $c['softDelete']
            ? "get{$c['entity']}List, get{$c['entity']}RecycleList, restore{$c['entity']}, purge{$c['entity']}, type {$c['entity']}ListRecord"
            : "get{$c['entity']}List, type {$c['entity']}ListRecord";
        $apiImports .= ", get{$c['entity']}Detail, add{$c['entity']}, edit{$c['entity']}, delete{$c['entity']}, type {$c['entity']}Detail, type {$c['entity']}CreateInput, type {$c['entity']}UpdateInput";
        $uiImports = 'ElAlert, ElButton, ElCard, ElTableColumn, ElPopconfirm, ElDialog, ElForm, ElFormItem, ElInput, ElSelect, ElOption, ElDescriptions, ElDescriptionsItem'
            . ($c['softDelete'] ? ', ElMessage' : '');
        $softImports = '';
        $hasSearch = $controls['filterFields'] !== '';
        $filterType = '{ ' . $controls['filterFields'] . ($c['softDelete'] ? 'recycle: boolean; ' : '') . '}';
        $initialFilters = '{ ' . $controls['filterDefaults'] . ($c['softDelete'] ? 'recycle: false' : '') . '}';
        $filterParameter = ($c['softDelete'] || $hasSearch) ? ', filters' : '';
        $savedFilters = $c['softDelete']
            ? 'const { recycle, ...filters } = searchForm.value;'
            : 'const filters = searchForm.value;';
        $fetchCall = $c['softDelete']
            ? "(filters.recycle ? get{$c['entity']}RecycleList : get{$c['entity']}List)"
            : "get{$c['entity']}List";
        $disabledPagination = $c['softDelete'] ? ' :pagination-disabled="actionLoading"' : '';
        $softHandlers = $c['softDelete'] ? self::replace(<<<'TS'

  const showRecycle = computed(() => searchForm.value.recycle);
  const action = useAsyncAction(() => '操作失败，请重试');
  const current = useCrudTab(action.cancel);
  const { loading: actionLoading, error: actionError } = action;
  const toggleRecycle = () => {
    if (actionLoading.value || !current.value || !can('recycle.list')) return;
    action.cancel();
    detailAction.cancel();
    visible.value = false;
    list.clear();
    searchForm.value.recycle = !showRecycle.value;
    void fetchData(1);
  };
  const handleRestore = async (record: unknown) => {
    if (!current.value || !can('restore')) return;
    const result = await action.run(async ({ signal }) => {
      const response = await restore{{entity}}([readPrimaryKey(record)], signal);
      if (!Array.isArray(response.data.failed) || response.data.failed.length !== 0) throw new Error('RESTORE_INCOMPLETE');
    });
    if (result.status !== 'completed') return;
    ElMessage.success('恢复成功');
    await fetchData(pagination.page);
  };
  const handlePurge = async (record: unknown) => {
    if (!current.value || !can('purge')) return;
    const result = await action.run(async ({ signal }) => {
      const response = await purge{{entity}}([readPrimaryKey(record)], signal);
      if (!Array.isArray(response.data.failed) || response.data.failed.length !== 0) throw new Error('PURGE_INCOMPLETE');
    });
    if (result.status !== 'completed') return;
    ElMessage.success('永久删除成功');
    await fetchData(pagination.page);
  };
TS, $c) : '';
        $batchEnabled = $c['capabilities']['batchDelete'] || $c['capabilities']['batchUpdateStatus'];
        $batchSlot = '';
        $batchHandlers = '';
        if ($batchEnabled) {
            $apiImports .= $c['capabilities']['batchDelete'] ? ", batchDelete{$c['entity']}" : '';
            $apiImports .= $c['capabilities']['batchUpdateStatus'] ? ", batchUpdateStatus{$c['entity']}" : '';
            $uiImports .= $c['capabilities']['batchUpdateStatus'] ? ', ElInputNumber' : '';
            $deleteButton = $c['capabilities']['batchDelete'] ? "        <ElPopconfirm title=\"确定删除所选记录？\" @confirm=\"handleBatchDelete(selection)\">\n          <template #reference><ElButton v-permission=\"['{$c['permissionPrefix']}.batchDelete']\" :disabled=\"batchLoading || selection.length > 100\">批量删除</ElButton></template>\n        </ElPopconfirm>\n" : '';
            $statusButton = $c['capabilities']['batchUpdateStatus'] ? "        <ElInputNumber v-model=\"batchStatus\" :precision=\"0\" :disabled=\"batchLoading\" />\n        <ElButton v-permission=\"['{$c['permissionPrefix']}.batchUpdateStatus']\" :disabled=\"batchLoading || batchStatus === undefined || selection.length > 100\" @click=\"handleBatchStatus(selection)\">批量修改状态</ElButton>\n" : '';
            $selectionColumn = $c['softDelete'] ? '<ElTableColumn v-if="!showRecycle" type="selection" />' : '<ElTableColumn type="selection" />';
            $condition = $c['softDelete'] ? ' v-if="!showRecycle"' : '';
            $batchSlot = "      <template #batch=\"{ selection }\"><div{$condition}>\n{$deleteButton}{$statusButton}      </div></template>\n";
            $batchHandlers = "  const batchAction = useAsyncAction(() => '批量操作失败，请重试');\n  const batchCurrent = useCrudTab(batchAction.cancel);\n  const { loading: batchLoading, error: batchError } = batchAction;\n";
            if ($c['capabilities']['batchUpdateStatus']) $batchHandlers .= "  const batchStatus = ref<number | undefined>(0);\n";
            $activeBatch = $c['softDelete'] ? '!showRecycle.value && ' : '';
            $batchHandlers .= "  const validBatchSelection = (selection: {$c['entity']}ListRecord[]) => {$activeBatch}selection.length > 0 && selection.length <= 100\n    && selection.every(row => findRecord(data.value, row.{$c['primary']}) !== undefined);\n";
            $batchHandlers .= "  const runBatch = async (permission: string, operation: (signal: AbortSignal) => Promise<unknown>) => {\n    if (!batchCurrent.value || batchLoading.value || !can(permission)) return;\n    const result = await batchAction.run(async ({ signal }) => operation(signal));\n    if (result.status === 'completed' && batchCurrent.value && can(permission)) await fetchData(pagination.page);\n  };\n";
            if ($c['capabilities']['batchDelete']) $batchHandlers .= "  const handleBatchDelete = (selection: {$c['entity']}ListRecord[]) => {\n    if (!validBatchSelection(selection)) return;\n    void runBatch('batchDelete', signal => batchDelete{$c['entity']}(selection.map(row => row.{$c['primary']}), signal));\n  };\n";
            if ($c['capabilities']['batchUpdateStatus']) $batchHandlers .= "  const handleBatchStatus = (selection: {$c['entity']}ListRecord[]) => {\n    const status = batchStatus.value;\n    if (validBatchSelection(selection) && status !== undefined) void runBatch('batchUpdateStatus', signal => batchUpdateStatus{$c['entity']}(selection.map(row => row.{$c['primary']}), status, signal));\n  };\n";
        } else {
            $selectionColumn = '';
        }
        $findRecord = $c['tree'] === []
            ? "    return rows.find(item => item.{$c['primary']} === id);"
            : "    for (const item of rows) {\n      if (item.{$c['primary']} === id) return item;\n      const child = findRecord(item.children ?? [], id);\n      if (child) return child;\n    }\n    return undefined;";
        $searchSlot = $hasSearch ? "      <template #search><ElForm inline>\n{$controls['searchItems']}      </ElForm></template>\n" : '';
        $normalOnly = $c['softDelete'] ? ' v-if="!showRecycle"' : '';
        $listResult = $c['tree'] === []
            ? 'return { items: response.data.lists, total: response.data.count, page, pageSize };'
            : 'return { items: response.data, total: response.data.length, page, pageSize };';
        return self::replace(<<<'VUE'
<template>
  <ElCard class="general-card" header="{{title}}">
{{recycleToggle}}{{batchErrorAlert}}    <ElAlert v-if="error" :title="error" type="error" :closable="false" />
    <ElButton v-if="error" :disabled="loading" @click="fetchData(pagination.page)">重新加载</ElButton>
    <CrudPage
      :loading="loading"
      :data="records"
      :pagination="pagination"
      :fields="columns"
      :saved-views="savedViews"{{inlineProps}}
      column-storage-key="peanut:crud:{{permissionPrefix}}:columns"
      row-key="{{primary}}"{{disabledPagination}}
      @page-change="fetchData"
      @size-change="handleSizeChange"
      @search="handleSearch"
      @reset="handleReset"
    >
{{searchSlot}}      <template #toolbar><ElButton{{normalOnly}} v-permission="['{{permissionPrefix}}.add']" type="primary" @click="openCreate">新建</ElButton></template>
{{batchSlot}}      <template #columns>
        {{selectionColumn}}
        <ElTableColumn{{normalOnly}} label="操作" :width="220">
          <template #default="{ row }">
            <ElButton v-permission="['{{permissionPrefix}}.detail']" link @click="openDetail(row, 'view')">详情</ElButton>
            <ElButton v-permission="['{{permissionPrefix}}.edit']" link @click="openDetail(row, 'edit')">编辑</ElButton>
            <ElPopconfirm title="确定删除该记录？" @confirm="deleteRow(row)">
              <template #reference><ElButton v-permission="['{{permissionPrefix}}.delete']" link type="danger">删除</ElButton></template>
            </ElPopconfirm>
          </template>
        </ElTableColumn>
{{actionSlot}}      </template>
      <template #empty>
        <span role="status">{{ loading ? '正在加载…' : error ? '加载失败' : '暂无数据' }}</span>
      </template>
    </CrudPage>
    <ElAlert v-if="detailError || deleteError" :title="detailError || deleteError || undefined" type="error" :closable="false" />
    <CrudDetailDrawer
      v-if="mode === 'view'"
      v-model="visible"
      title="详情"{{historyProp}}
    >
      <ElDescriptions :column="1" border>
{{detailItems}}      </ElDescriptions>
    </CrudDetailDrawer>
    <ElDialog v-else v-model="visible" :title="mode === 'create' ? '新建' : '编辑'">
      <ElAlert v-if="submitError" :title="submitError" type="error" :closable="false" />
      <ElForm ref="formRef" :model="form" label-width="120px">
{{formItems}}      </ElForm>
      <template #footer>
        <ElButton @click="visible = false">关闭</ElButton>
        <ElButton type="primary" :loading="submitLoading" @click="submit">保存</ElButton>
      </template>
    </ElDialog>
  </ElCard>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue';
  import { {{uiImports}} } from 'element-plus';
  import { useRoute } from 'vue-router';
  import { useAsyncAction } from '@peanut-admin/vue';
  import { hasPermission } from '@/hooks/permission';
  import { useUserStore } from '@/store';
  import CrudPage from '@/components/crud/CrudPage.vue';
  import CrudDetailDrawer from '@/components/crud/CrudDetailDrawer.vue';
  import useCrudModal from '@/hooks/useCrudModal';
  import useCrudTab from '@/hooks/useCrudTab';
  import useCrudTable from '@/hooks/useCrudTable';
{{softImports}}  import { {{apiImports}} } from './api';

  interface ListColumn {
    label: string;
    key: keyof {{entity}}ListRecord;
    editable?: boolean;
  }
  const columns: ListColumn[] = [
{{tableColumns}}  ];
  const list = useCrudTable<{{entity}}ListRecord, {{filterType}}>({
    initialFilters: {{initialFilters}},
    initialPageSize: 15,
    load: async ({ page, pageSize, signal{{filterParameter}} }) => {
      const response = await {{fetchCall}}({ page_no: page, page_size: pageSize,
{{filterParams}}      }, signal);
      {{listResult}}
    },
    errorMessage: () => '列表加载失败，请重试',
    deleteRecord: async (record, signal) => {
      if (!can('delete')) throw new Error('无权删除');
      await delete{{entity}}(record.{{primary}}, signal);
    },
  });
  const { data, pagination, loading, error, deleteError, fetchList: fetchData, searchForm, handleSearch, handleReset, handleDelete } = list;
  const user = useUserStore();
  const savedViews = computed(() => {
    const { tenantId, accountId } = user;
    if (!tenantId || !accountId) return undefined;
    {{savedFilters}}
    return {
      pageKey: '{{permissionPrefix}}',
      tenantId,
      userId: accountId,
      filters,
      applyFilters: (selected: typeof filters) => {
        const query: Record<string, unknown> = searchForm.value;
        for (const key of Object.keys(filters)) {
          const value = Reflect.get(selected, key);
          query[key] = typeof value === 'string' ? value : '';
        }
        void handleSearch();
      },
    };
  });
  const records = computed(() => [...data.value]);
  const handleSizeChange = (size: number) => {
    pagination.pageSize = size;
    void fetchData(1);
  };
  // Element Plus exposes a generic row slot; validate only the key used by this action.
  const readPrimaryKey = (record: unknown): {{primaryTsType}} => {
    if (typeof record !== 'object' || record === null || Array.isArray(record)
      || !('{{primary}}' in record) || typeof record.{{primary}} !== '{{primaryTsType}}') {
      throw new Error('GENERATED_ROW_PRIMARY_KEY_INVALID');
    }
    return record.{{primary}};
  };
  const route = useRoute();
  const can = (action: string) => hasPermission(`{{permissionPrefix}}.${action}`, route.path);
  const detailAction = useAsyncAction(() => '详情加载失败，请重试');
  const { error: detailError } = detailAction;
  const pageCurrent = useCrudTab(detailAction.cancel);
  const detailRecord = ref<{{entity}}Detail>();
  const detailMode = ref<'edit' | 'view'>('view');
  watch(() => [can('detail'), can('edit')], ([detail, edit]) => {
    if (!detail || (detailMode.value === 'edit' && !edit)) detailAction.cancel();
  });
  const activeId = ref<{{primaryTsType}}>();
{{parsers}}  const modal = useCrudModal({
    canOpen: (nextMode) => can(nextMode === 'create' ? 'add' : nextMode === 'edit' ? 'edit' : 'detail')
      && (nextMode === 'create' || can('detail')),
    createForm: () => ({
{{formDefaults}}    }),
    editForm: (record: {{entity}}Detail) => ({
{{editDefaults}}    }),
    save: async (form, nextMode, signal) => {
      if (!can(nextMode === 'create' ? 'add' : 'edit')) throw new Error('无权执行该操作');
      if (nextMode === 'create') {
        const input: {{entity}}CreateInput = {
{{createFields}}        };
        await add{{entity}}(input, signal);
      } else {
        if (activeId.value === undefined) throw new Error('未选择操作对象');
        const input: {{entity}}UpdateInput = {
          {{primary}}: activeId.value,
{{updateFields}}        };
        await edit{{entity}}(input, signal);
      }
    },
    onSaved: () => fetchData(pagination.page).then(() => undefined),
    errorMessage: () => '保存失败，请检查输入后重试',
  });
  const { visible, mode, form, formRef, submit, submitError, submitLoading } = modal;
  const openCreate = () => {
    if (!pageCurrent.value || !can('add')) return;
    detailAction.cancel();
    void modal.create();
  };
  const openDetail = async (row: unknown, nextMode: 'edit' | 'view') => {
    if (!pageCurrent.value || !can('detail') || (nextMode === 'edit' && !can('edit'))) return;
    const id = readPrimaryKey(row);
    detailMode.value = nextMode;
    const result = await detailAction.run(async ({ signal }) => (await get{{entity}}Detail(id, signal)).data);
    if (result.status !== 'completed' || !pageCurrent.value || !can('detail') || (nextMode === 'edit' && !can('edit'))) return;
    activeId.value = id;
    detailRecord.value = result.data;
    if (nextMode === 'edit') await modal.edit(result.data);
    else await modal.view(result.data);
  };
  const findRecord = (rows: readonly {{entity}}ListRecord[], id: {{primaryTsType}}): {{entity}}ListRecord | undefined => {
{{findRecord}}
  };
  const deleteRow = (row: unknown) => {
    if (!pageCurrent.value || !can('delete')) return;
    const record = findRecord(data.value, readPrimaryKey(row));
    if (record) void handleDelete(record);
  };
{{inlineHandlers}}{{softHandlers}}{{batchHandlers}}  void fetchData();
</script>
VUE, $c + $controls + compact(
            'recycleToggle',
            'actionSlot',
            'apiImports',
            'uiImports',
            'softImports',
            'filterType',
            'initialFilters',
            'filterParameter',
            'fetchCall',
            'disabledPagination',
            'savedFilters',
            'inlineProps',
            'inlineHandlers',
            'softHandlers',
            'searchSlot',
            'normalOnly',
            'findRecord',
            'batchSlot',
            'batchHandlers',
            'selectionColumn',
        ) + [
            'tableColumns' => $columns,
            'historyProp' => $historyProp,
            'listResult' => $listResult,
            'batchErrorAlert' => $batchEnabled ? "    <ElAlert v-if=\"batchError\" :title=\"batchError\" type=\"error\" :closable=\"false\" />\n" : '',
        ]);
    }

    private static function renderFrontendContribution(array $c): string
    {
        return self::replace(<<<'TS'
import type { ModuleFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: ModuleFrontendContribution = {
  moduleKey: '{{moduleKey}}',
  routes: [{
    path: '/generated/{{resource}}',
    name: '{{moduleKey}}.{{resource}}',
    component: DEFAULT_LAYOUT,
    meta: {
      locale: '{{title}}',
      requiresAuth: true,
      tenantModuleKey: '{{moduleKey}}',
      requiredPermissions: '{{permissionPrefix}}.list',
    },
    children: [{
      path: '',
      name: '{{moduleKey}}.{{resource}}.list',
      component: () => import('./index.vue'),
      meta: { locale: '{{title}}', requiresAuth: true, tenantModuleKey: '{{moduleKey}}', requiredPermissions: '{{permissionPrefix}}.list' },
    }],
  }],
};

export default contribution;
TS, $c);
    }

    private static function renderRouteFragment(array $c): string
    {
        $softRoutes = $c['softDelete'] ? self::replace(<<<'PHP'
    Route::get('{{permissionPrefix}}.recycle.list', [{{entity}}Controller::class, 'recycleLists'])->option(['peanut_permission' => '{{permissionPrefix}}.recycle.list']);
    Route::get('{{permissionPrefix}}.recycle.detail', [{{entity}}Controller::class, 'recycleDetail'])->option(['peanut_permission' => '{{permissionPrefix}}.recycle.detail']);
    Route::post('{{permissionPrefix}}.restore', [{{entity}}Controller::class, 'restore'])->option(['peanut_permission' => '{{permissionPrefix}}.restore']);
    Route::post('{{permissionPrefix}}.purge', [{{entity}}Controller::class, 'purge'])->option(['peanut_permission' => '{{permissionPrefix}}.purge']);
PHP, $c) : '';
        $batchRoutes = '';
        foreach (['batchDelete', 'batchUpdateStatus'] as $action) {
            if ($c['capabilities'][$action]) $batchRoutes .= "    Route::post('{$c['permissionPrefix']}.{$action}', [{$c['entity']}Controller::class, '{$action}'])->option(['peanut_permission' => '{$c['permissionPrefix']}.{$action}']);\n";
        }
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use {{namespace}}Controller\{{entity}}Controller;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') return;

Route::group(function (): void {
    Route::get('{{permissionPrefix}}.list', [{{entity}}Controller::class, 'lists'])->option(['peanut_permission' => '{{permissionPrefix}}.list']);
    Route::get('{{permissionPrefix}}.detail', [{{entity}}Controller::class, 'detail'])->option(['peanut_permission' => '{{permissionPrefix}}.detail']);
    Route::post('{{permissionPrefix}}.add', [{{entity}}Controller::class, 'add'])->option(['peanut_permission' => '{{permissionPrefix}}.add']);
    Route::post('{{permissionPrefix}}.edit', [{{entity}}Controller::class, 'edit'])->option(['peanut_permission' => '{{permissionPrefix}}.edit']);
    Route::post('{{permissionPrefix}}.delete', [{{entity}}Controller::class, 'delete'])->option(['peanut_permission' => '{{permissionPrefix}}.delete']);
{{softRoutes}}{{batchRoutes}}})->middleware([
    LoginMiddleware::class,
    [OfficialModuleMiddleware::class, ['{{moduleKey}}', 'http.admin']],
    AuthMiddleware::class,
    OperationLogMiddleware::class,
]);
PHP, $c + compact('softRoutes', 'batchRoutes'));
    }

    private static function renderOpenApiFragment(array $c): string
    {
        $policies = self::fieldPolicies($c);
        $columns = array_column($c['columns'], null, 'name');
        $schemaRef = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
        $responseRef = static fn(string $name): array => ['$ref' => '#/components/responses/' . $name];
        $columnSchema = static function (string $name) use ($columns, $schemaRef, $c): array {
            if ($name === $c['primary']) {
                return $schemaRef($c['entity'] . 'PrimaryKey');
            }
            $column = $columns[$name] ?? null;
            if (!is_array($column)) {
                throw new RuntimeException('接口字段政策引用了未知字段：' . $name);
            }
            $schema = ['type' => $column['openApiType']];
            if ($column['length'] > 0 && $column['openApiType'] === 'string') {
                $schema['maxLength'] = $column['length'];
            }
            if ($column['enum'] !== []) {
                $schema['enum'] = $column['enum'];
            }
            return $schema;
        };
        $objectSchema = static function (array $fields, array $required = []) use ($columnSchema): array {
            $properties = [];
            foreach ($fields as $field) {
                $properties[$field] = $columnSchema($field);
            }
            $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
            if ($required !== []) {
                $schema['required'] = array_values($required);
            }
            return $schema;
        };

        $listProperties = $objectSchema($c['listFields'], [$c['primary']])['properties'];
        $detailProperties = $objectSchema($c['detailFields'], [$c['primary']])['properties'];
        foreach ($c['relations'] as $relation) {
            if ($relation['summaryFields'] === []) {
                continue;
            }
            $summaryProperties = [];
            foreach ($relation['summaryFields'] as $field) {
                $summaryProperties[$field] = ['type' => self::openApiType($relation['summaryTypes'][$field])];
            }
            $summary = ['type' => 'object', 'additionalProperties' => false, 'properties' => $summaryProperties];
            $relationSchema = $relation['method'] === 'hasMany'
                ? ['type' => 'array', 'items' => $summary]
                : $summary;
            $listProperties[$relation['name']] = $relationSchema;
            $detailProperties[$relation['name']] = $relationSchema;
        }

        if ($policies['diffFieldsArray'] !== []) {
            $snapshot = ['type' => 'object', 'additionalProperties' => true];
            $detailProperties['audit_history'] = [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'occurred_at', 'before', 'after'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'occurred_at' => ['type' => 'string'],
                        'before' => $snapshot,
                        'after' => $snapshot,
                    ],
                ],
            ];
        }

        $primarySchema = ['type' => $c['primaryType'] === 'int' ? 'integer' : 'string'];
        if ($c['primaryType'] === 'int') {
            $primarySchema['minimum'] = 1;
        } elseif ($c['primaryLength'] > 0) {
            $primarySchema['maxLength'] = $c['primaryLength'];
        }

        $createRequired = [];
        foreach ($policies['insertFieldsArray'] as $field) {
            if (($columns[$field]['required'] ?? false) === true) {
                $createRequired[] = $field;
            }
        }
        $schemas = [
            $c['entity'] . 'PrimaryKey' => $primarySchema,
            $c['entity'] . 'ListRecord' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $listProperties, 'required' => [$c['primary']]],
            $c['entity'] . 'Detail' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $detailProperties, 'required' => [$c['primary']]],
            $c['entity'] . 'CreateRequest' => $objectSchema($policies['insertFieldsArray'], $createRequired),
            $c['entity'] . 'UpdateRequest' => $objectSchema([$c['primary'], ...$policies['updateFieldsArray']], [$c['primary']]),
            $c['entity'] . 'DeleteRequest' => $objectSchema([$c['primary']], [$c['primary']]),
            $c['entity'] . 'ListData' => $c['tree'] === [] ? [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $schemaRef($c['entity'] . 'ListRecord')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
            ] : ['type' => 'array', 'items' => $schemaRef($c['entity'] . 'ListRecord')],
            $c['entity'] . 'MutationData' => ['type' => 'array', 'maxItems' => 0, 'items' => []],
        ];

        $envelope = static fn(array $data): array => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]],
                'msg' => ['type' => 'string'],
                'data' => $data,
            ],
        ];
        $schemas[$c['entity'] . 'ListResponse'] = $envelope($schemaRef($c['entity'] . 'ListData'));
        $schemas[$c['entity'] . 'DetailResponse'] = $envelope($schemaRef($c['entity'] . 'Detail'));
        $schemas[$c['entity'] . 'MutationResponse'] = $envelope($schemaRef($c['entity'] . 'MutationData'));

        $responses = [
            $c['entity'] . 'ListSuccess' => ['description' => '列表查询成功', 'content' => ['application/json' => ['schema' => $schemaRef($c['entity'] . 'ListResponse')]]],
            $c['entity'] . 'DetailSuccess' => ['description' => '详情查询成功', 'content' => ['application/json' => ['schema' => $schemaRef($c['entity'] . 'DetailResponse')]]],
            $c['entity'] . 'MutationSuccess' => ['description' => '操作成功', 'content' => ['application/json' => ['schema' => $schemaRef($c['entity'] . 'MutationResponse')]]],
        ];
        $requestBody = static fn(string $schema): array => [
            'required' => true,
            'content' => ['application/json' => ['schema' => $schemaRef($schema)]],
        ];
        $queryParameter = static fn(string $name, array $schema, bool $required = false): array => [
            'in' => 'query', 'name' => $name, 'required' => $required, 'schema' => $schema,
        ];
        $listParameters = [];
        foreach ($policies['listFieldsArray'] as $field) {
            $schema = match ($field) {
                'page_no' => ['type' => 'integer', 'minimum' => 1],
                'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                default => $columnSchema($field),
            };
            $listParameters[] = $queryParameter($field, $schema);
        }
        $operation = static function (
            string $action,
            string $method,
            string $response,
            array $parameters = [],
            ?string $body = null,
            array $errors = [],
        ) use ($c, $responseRef, $requestBody): array {
            $value = [
                'operationId' => str_replace('.', '', $action) . $c['entity'],
                'summary' => $c['title'] . $action,
                'tags' => [$c['moduleKey']],
                'parameters' => $parameters,
                'responses' => [
                    '200' => $responseRef($response),
                    '401' => ['description' => '未登录'],
                    '403' => ['description' => '无权执行该操作'],
                    '404' => ['description' => '对象不存在'],
                    '409' => ['description' => '状态、唯一性或关联冲突'],
                    '422' => ['description' => '请求参数校验失败'],
                ],
                'x-peanut-permission' => $c['permissionPrefix'] . '.' . $action,
                'x-peanut-errors' => array_values(array_unique([
                    $c['errorPrefix'] . '_PERMISSION_DENIED',
                    ...$errors,
                ])),
            ];
            if ($body !== null) {
                $value['requestBody'] = $requestBody($body);
            }
            return [$method => $value];
        };

        $prefix = '/adminapi/' . $c['permissionPrefix'] . '.';
        $pkParameter = [$queryParameter($c['primary'], $schemaRef($c['entity'] . 'PrimaryKey'), true)];
        $paths = [
            $prefix . 'list' => $operation('list', 'get', $c['entity'] . 'ListSuccess', $listParameters),
            $prefix . 'detail' => $operation('detail', 'get', $c['entity'] . 'DetailSuccess', $pkParameter, errors: [$c['errorPrefix'] . '_NOT_FOUND']),
            $prefix . 'add' => $operation('add', 'post', $c['entity'] . 'MutationSuccess', body: $c['entity'] . 'CreateRequest', errors: [$c['errorPrefix'] . '_SAVE_FAILED']),
            $prefix . 'edit' => $operation('edit', 'post', $c['entity'] . 'MutationSuccess', body: $c['entity'] . 'UpdateRequest', errors: [$c['errorPrefix'] . '_NOT_FOUND', $c['errorPrefix'] . '_SAVE_FAILED']),
            $prefix . 'delete' => $operation('delete', 'post', $c['entity'] . 'MutationSuccess', body: $c['entity'] . 'DeleteRequest', errors: [$c['errorPrefix'] . '_NOT_FOUND', $c['errorPrefix'] . '_DELETE_FAILED']),
        ];

        foreach (['batchDelete', 'batchUpdateStatus'] as $action) {
            if (!$c['capabilities'][$action]) continue;
            $schema = $c['entity'] . ucfirst($action) . 'Request';
            $properties = ['ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => $schemaRef($c['entity'] . 'PrimaryKey')]];
            $required = ['ids'];
            if ($action === 'batchUpdateStatus') {
                $properties['status'] = ['type' => 'integer'];
                $required[] = 'status';
            }
            $schemas[$schema] = ['type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties];
            $paths[$prefix . $action] = $operation($action, 'post', $c['entity'] . 'MutationSuccess', body: $schema);
        }

        if ($c['softDelete']) {
            $schemas[$c['entity'] . 'BatchRequest'] = [
                'type' => 'object', 'additionalProperties' => false,
                'oneOf' => [['required' => [$c['primary']]], ['required' => ['ids']]],
                'properties' => [
                    $c['primary'] => $schemaRef($c['entity'] . 'PrimaryKey'),
                    'ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => $schemaRef($c['entity'] . 'PrimaryKey')],
                ],
            ];
            $schemas[$c['entity'] . 'BatchFailure'] = [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [$c['primary'], 'code', 'message'],
                'properties' => [
                    $c['primary'] => $schemaRef($c['entity'] . 'PrimaryKey'),
                    'code' => ['type' => 'string'], 'message' => ['type' => 'string'],
                ],
            ];
            $schemas[$c['entity'] . 'BatchResult'] = [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['requested', 'already_active', 'failed'],
                'properties' => [
                    'requested' => ['type' => 'array', 'items' => $schemaRef($c['entity'] . 'PrimaryKey')],
                    'restored' => ['type' => 'array', 'items' => $schemaRef($c['entity'] . 'PrimaryKey')],
                    'purged' => ['type' => 'array', 'items' => $schemaRef($c['entity'] . 'PrimaryKey')],
                    'already_active' => ['type' => 'array', 'items' => $schemaRef($c['entity'] . 'PrimaryKey')],
                    'failed' => ['type' => 'array', 'items' => $schemaRef($c['entity'] . 'BatchFailure')],
                ],
            ];
            $schemas[$c['entity'] . 'BatchResponse'] = $envelope($schemaRef($c['entity'] . 'BatchResult'));
            $responses[$c['entity'] . 'BatchSuccess'] = ['description' => '批量操作结果', 'content' => ['application/json' => ['schema' => $schemaRef($c['entity'] . 'BatchResponse')]]];
            $paths += [
                $prefix . 'recycle.list' => $operation('recycle.list', 'get', $c['entity'] . 'ListSuccess', $listParameters),
                $prefix . 'recycle.detail' => $operation('recycle.detail', 'get', $c['entity'] . 'DetailSuccess', $pkParameter, errors: [$c['errorPrefix'] . '_NOT_FOUND']),
                $prefix . 'restore' => $operation('restore', 'post', $c['entity'] . 'BatchSuccess', body: $c['entity'] . 'BatchRequest', errors: [
                    $c['errorPrefix'] . '_NOT_FOUND', $c['errorPrefix'] . '_RESTORE_UNIQUE_CONFLICT', $c['errorPrefix'] . '_RESTORE_RELATION_CONFLICT',
                ]),
                $prefix . 'purge' => $operation('purge', 'post', $c['entity'] . 'BatchSuccess', body: $c['entity'] . 'BatchRequest', errors: [
                    $c['errorPrefix'] . '_NOT_FOUND', $c['errorPrefix'] . '_PURGE_REQUIRES_TRASHED', $c['errorPrefix'] . '_PURGE_RELATION_CONFLICT',
                ]),
            ];
        }
        $document = [
            'paths' => $paths,
            'components' => ['schemas' => $schemas, 'responses' => $responses],
        ];
        return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($document, true) . ";\n";
    }

    private static function mergeManifest(array $c): string
    {
        $manifest = $c['manifest'];
        $tables = $manifest['database']['owned_tables'] ?? [];
        if (!is_array($tables)) {
            throw new RuntimeException('目标模块 owned_tables 声明无效');
        }
        $tables[] = $c['databaseTable'];
        $tables = array_values(array_unique(array_map('strval', $tables)));
        sort($tables, SORT_STRING);
        $manifest['database']['owned_tables'] = $tables;
        return self::json($manifest);
    }

    private static function mergePermissions(array $c): string
    {
        try {
            $permissions = json_decode($c['permissionsSource'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('目标模块权限清单无效', 0, $exception);
        }
        if (!is_array($permissions) || !array_is_list($permissions)) {
            throw new RuntimeException('目标模块权限清单必须是列表');
        }
        $existing = array_column($permissions, 'key');
        $actions = [
            'list' => ['api', 'normal', '查询'], 'detail' => ['api', 'normal', '查看'],
            'add' => ['action', 'sensitive', '新增'], 'edit' => ['action', 'sensitive', '修改'],
            'delete' => ['action', 'critical', $c['softDelete'] ? '软删除' : '删除'],
        ];
        if ($c['softDelete']) {
            $actions += [
                'recycle.list' => ['api', 'sensitive', '查看回收站'],
                'recycle.detail' => ['api', 'sensitive', '查看已删除'],
                'restore' => ['action', 'critical', '恢复'],
                'purge' => ['action', 'critical', '永久删除'],
            ];
        }
        if ($c['capabilities']['batchDelete']) $actions['batchDelete'] = ['action', 'critical', '批量删除'];
        if ($c['capabilities']['batchUpdateStatus']) $actions['batchUpdateStatus'] = ['action', 'sensitive', '批量修改状态'];
        foreach ($actions as $action => [$type, $risk, $verb]) {
            $key = $c['permissionPrefix'] . '.' . $action;
            if (in_array($key, $existing, true)) {
                throw new RuntimeException('目标模块已登记同名权限：' . $key);
            }
            $permissions[] = ['key' => $key, 'type' => $type, 'name' => $verb . $c['title'], 'risk_level' => $risk];
        }
        return self::json($permissions);
    }

    private static function mergeRouteRegistry(array $c): string
    {
        $include = "require __DIR__ . '/generated/{$c['resource']}.php';";
        if (str_contains($c['routeSource'], $include)) {
            throw new RuntimeException('目标模块已登记同名生成路由');
        }
        return rtrim($c['routeSource']) . "\n\n{$include}\n";
    }

    private static function mergeOpenApiRegistry(array $c): string
    {
        $include = "require __DIR__ . '/generated/{$c['resource']}.php'";
        if (!$c['openApiExists']) {
            return "<?php\ndeclare(strict_types=1);\n\nreturn {$include};\n";
        }
        if (str_contains($c['openApiSource'], $include)) {
            throw new RuntimeException('目标模块已登记同名接口元数据');
        }
        preg_match_all('/^return\s+\[/m', $c['openApiSource'], $matches, PREG_OFFSET_CAPTURE);
        if (count($matches[0] ?? []) !== 1) {
            throw new RuntimeException('目标模块 OpenAPI 元数据不是可安全合并的单一顶层 return 数组');
        }
        $offset = $matches[0][0][1];
        $source = substr_replace($c['openApiSource'], '$base = [', $offset, strlen($matches[0][0][0]));
        $trimmed = rtrim($source);
        if (!str_ends_with($trimmed, '];')) {
            throw new RuntimeException('目标模块 OpenAPI 元数据结尾无法安全合并');
        }
        return $trimmed . "\n\nreturn array_replace_recursive(\$base, {$include});\n";
    }

    private static function mergeFrontendContribution(array $c): string
    {
        $variable = 'generated' . $c['entity'] . 'Contribution';
        $import = "import {$variable} from './generated/{$c['resource']}/contribution';";
        if (str_contains($c['frontendContributionSource'], $import)) {
            throw new RuntimeException('目标模块已登记同名生成前端贡献');
        }
        $export = 'export default contribution;';
        if (substr_count($c['frontendContributionSource'], $export) !== 1) {
            throw new RuntimeException('目标模块前端贡献不是可安全合并的标准结构');
        }
        return str_replace(
            $export,
            "contribution.routes.push(...{$variable}.routes);\n\n{$export}",
            $import . "\n" . $c['frontendContributionSource'],
        );
    }

    /** @return array<string,mixed> */
    private static function fieldPolicies(array $c): array
    {
        $system = array_values(array_unique(array_filter([
            $c['primary'], 'tenant_id', 'create_time', 'update_time', 'delete_time', $c['softDeleteField'],
        ])));
        $insert = array_values(array_map(
            static fn(array $column): string => $column['name'],
            array_filter($c['columns'], static fn(array $column): bool => $column['insert'] && !in_array($column['name'], $system, true)),
        ));
        $update = array_values(array_map(
            static fn(array $column): string => $column['name'],
            array_filter($c['columns'], static fn(array $column): bool => $column['update'] && !in_array($column['name'], $system, true)),
        ));
        $list = array_values(array_unique([
            ...array_map(
                static fn(array $column): string => $column['name'],
                array_filter($c['columns'], static fn(array $column): bool => $column['query'] && self::publicColumn($column['name'], $c['softDeleteField'])),
            ),
            'page_no', 'page_size',
        ]));
        // The detail projection already excludes sensitive columns.
        $diff = array_values(array_intersect($update, $c['detailFields']));
        if ($diff !== [] && (in_array('audit_history', $c['detailFields'], true)
            || in_array('audit_history', array_column($c['relations'], 'name'), true))) {
            throw new RuntimeException('audit_history 是生成详情的保留字段');
        }
        return [
            'diffFieldsArray' => $diff,
            'insertFieldsArray' => $insert,
            'updateFieldsArray' => $update,
            'listFieldsArray' => $list,
            'insertFields' => var_export($insert, true),
            'updateFields' => var_export($update, true),
            'listFields' => var_export($list, true),
        ];
    }

    /** @return array{inputFields:string,writableFields:string} */
    private static function controllerFieldPolicies(array $c): array
    {
        $fields = self::fieldPolicies($c);
        $input = [
            'lists' => $fields['listFieldsArray'], 'detail' => [$c['primary']],
            'add' => $fields['insertFieldsArray'], 'edit' => [$c['primary'], ...$fields['updateFieldsArray']],
            'delete' => [$c['primary']],
        ];
        if ($c['softDelete']) {
            $input += [
                'recycle' => $fields['listFieldsArray'],
                'recycleDetail' => [$c['primary']],
                'restore' => [$c['primary'], 'ids'],
                'purge' => [$c['primary'], 'ids'],
            ];
        }
        if ($c['capabilities']['batchDelete']) $input['batchDelete'] = ['ids' => true];
        if ($c['capabilities']['batchUpdateStatus']) $input['batchUpdateStatus'] = ['ids' => true, 'status'];
        return [
            'inputFields' => var_export($input, true),
            'writableFields' => var_export(['add' => $fields['insertFieldsArray'], 'edit' => $fields['updateFieldsArray']], true),
        ];
    }

    /** @param list<string> $fieldNames */
    private static function typescriptFields(array $c, array $fieldNames, bool $includeRelationSummaries = true, bool $partial = false): string
    {
        $byName = array_column($c['columns'], null, 'name');
        $result = '';
        foreach ($fieldNames as $name) {
            $column = $byName[$name] ?? null;
            if (!is_array($column)) {
                continue;
            }
            $result .= "  {$name}" . ($column['primary'] || (!$partial && $column['required']) ? '' : '?') . ": {$column['tsType']};\n";
        }
        foreach ($includeRelationSummaries ? $c['relations'] : [] as $relation) {
            if ($relation['summaryFields'] === []) {
                continue;
            }
            $summary = implode(' ', array_map(
                static fn(string $field): string => $field . ': ' . self::tsType($relation['summaryTypes'][$field]) . ';',
                $relation['summaryFields'],
            ));
            $result .= "  {$relation['name']}?: { {$summary} }" . ($relation['method'] === 'hasMany' ? '[]' : '') . ";\n";
        }
        return $result;
    }

    /** @param list<string> $fieldNames */
    private static function typescriptQueryFields(array $c, array $fieldNames): string
    {
        $byName = array_column($c['columns'], null, 'name');
        $result = '';
        foreach ($fieldNames as $name) {
            $type = in_array($name, ['page_no', 'page_size'], true)
                ? 'number'
                : (($byName[$name]['tsType'] ?? null) ?: 'string');
            $result .= "  {$name}?: {$type};\n";
        }
        return $result;
    }

    private static function replace(string $template, array $values): string
    {
        $replace = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $replace['{{' . $key . '}}'] = (string) $value;
            }
        }
        return strtr($template, $replace) . "\n";
    }

    private static function repositoryRoot(?string $sourceRoot = null): string
    {
        $root = realpath($sourceRoot ?? dirname(__DIR__, 5));
        if ($root === false || !is_dir($root . '/server/app/modules')) {
            throw new RuntimeException('应用源码根目录不可用');
        }
        return $root;
    }

    private static function readFile(string $relative, string $root): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new RuntimeException('模块登记路径无效');
        }
        $path = $root . '/' . $relative;
        $real = realpath($path);
        if ($real === false || !is_file($real) || is_link($path) || !str_starts_with($real, $root . '/')) {
            throw new RuntimeException('模块登记文件不存在或越界：' . $relative);
        }
        $content = file_get_contents($real);
        if (!is_string($content)) {
            throw new RuntimeException('模块登记文件无法读取：' . $relative);
        }
        return $content;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function kebab(string $value): string
    {
        return strtolower(str_replace('_', '-', preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?? $value));
    }

    private static function safeIdentifier(string $value): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $value) !== 1) {
            throw new RuntimeException('字段或关联名称不符合生成规范');
        }
        return $value;
    }

    private static function plainText(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0", "'", '"', '\\', '<', '>', '{', '}'], '', $value));
    }

    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'YES', 'true'], true);
    }

    private static function columnLength(array $column): int
    {
        if (isset($column['max_length'])) {
            return max(0, (int) $column['max_length']);
        }
        return preg_match('/\((\d+)\)/', (string) ($column['column_type'] ?? ''), $matches) === 1 ? (int) $matches[1] : 0;
    }

    /** @return list<string> */
    private static function columnEnum(array $column): array
    {
        $columnType = trim((string) ($column['column_type'] ?? ''));
        if (preg_match('/^enum\((.*)\)$/iD', $columnType, $matches) !== 1) {
            return [];
        }
        if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $values) === false) {
            return [];
        }
        return array_values(array_unique(array_map(
            static fn(string $value): string => stripcslashes($value),
            $values[1],
        )));
    }

    private static function tsType(string $type): string
    {
        if ($type === 'array' || $type === 'json') {
            return 'Record<string, unknown> | unknown[]';
        }
        return in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'float', 'double'], true) ? 'number' : 'string';
    }

    private static function openApiType(string $type): string
    {
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
            return 'integer';
        }
        if (in_array($type, ['decimal', 'float', 'double'], true)) {
            return 'number';
        }
        if ($type === 'array' || $type === 'json') {
            return 'object';
        }
        return 'string';
    }

    private static function validationRule(string $type, int $length): string
    {
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
            return 'integer|filter:int';
        }
        if (in_array($type, ['decimal', 'float', 'double'], true)) {
            return 'float';
        }
        if ($type === 'array' || $type === 'json') {
            return 'array';
        }
        return $length > 0 ? 'string|max:' . $length : 'string';
    }
}
