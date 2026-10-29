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
    public static function render(array $table): array
    {
        $context = self::context($table);
        $files = [
            self::createFile($context['modelPath'], 'php', self::renderModel($context)),
            self::createFile($context['servicePath'], 'php', self::renderService($context)),
            self::createFile($context['controllerPath'], 'php', self::renderController($context)),
            self::createFile($context['validatePath'], 'php', self::renderValidate($context)),
            self::createFile($context['apiPath'], 'typescript', self::renderApi($context)),
            self::createFile($context['viewPath'], 'vue', self::renderView($context)),
            self::createFile($context['frontendContributionFragmentPath'], 'typescript', self::renderFrontendContribution($context)),
            self::createFile($context['routeFragmentPath'], 'php', self::renderRouteFragment($context)),
            self::createFile($context['openApiFragmentPath'], 'php', self::renderOpenApiFragment($context)),
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
            : self::createFile($context['openApiPath'], 'php', $openApiRegistry);
        if ($context['hostRouteMergeRequired']) {
            $files[] = self::mergeFile(
                $context['hostRoutePath'],
                'php',
                self::mergeHostRouteRegistry($context),
                $context['hostRouteSource'],
            );
        }
        return $files;
    }

    /** 保存配置时也可调用；目标必须是现有唯一 Module，而不是任意目录名。 */
    public static function assertRegisteredModule(string $module): void
    {
        self::module($module);
    }

    /** @return array{path:string,language:string,content:string,operation:string} */
    private static function createFile(string $path, string $language, string $content): array
    {
        $absolute = self::repositoryRoot() . '/' . $path;
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
    private static function context(array $table): array
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

        $module = self::module(trim((string) ($table['module_name'] ?? '')));
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
        $relations = self::relations($table['relations'] ?? [], $module, $edition);
        $projection = self::projection($columns, $primary, $tree, $softDelete['field']);
        $backend = $module['backendRelative'];
        $frontend = $module['frontendRelative'];
        $namespace = $module['namespace'];
        $permissionPrefix = $module['key'] . '.' . $resource;
        $primaryType = self::primaryType($primary);
        $openApiPath = $backend . '/api/metadata/openapi.php';
        $openApiAbsolute = self::repositoryRoot() . '/' . $openApiPath;
        $openApiSource = is_file($openApiAbsolute) ? self::readFile($openApiPath) : '';
        $hostRoutePath = 'server/app/adminapi/route/app.php';
        $hostRouteSource = self::readFile($hostRoutePath);
        $moduleRouteRegistration = "'{$backend}/route/app.php',";

        return $module + [
            'entity' => $entity,
            'resource' => $resource,
            'tableName' => str_starts_with($tableName, 'pa_') ? substr($tableName, 3) : $tableName,
            'databaseTable' => $tableName,
            'title' => $title !== '' ? $title : $entity,
            'columns' => $columns,
            'primary' => $primary['name'],
            'primaryType' => $primaryType,
            'primaryTsType' => $primaryType === 'int' ? 'number' : 'string',
            'primaryLength' => (int) $primary['length'],
            'primaryRule' => self::primaryRule($primary, $primaryType),
            'tree' => $tree,
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
            'hostRoutePath' => $hostRoutePath,
            'hostRouteSource' => $hostRouteSource,
            'hostRouteMergeRequired' => !str_contains($hostRouteSource, $moduleRouteRegistration),
            'moduleRouteRegistration' => $moduleRouteRegistration,
        ];
    }

    /** @return array<string,mixed> */
    private static function module(string $input): array
    {
        if ($input === '' || preg_match('/^[a-z][a-z0-9_-]{0,31}(?:\.[a-z][a-z0-9-]{0,31})?$/D', $input) !== 1) {
            throw new RuntimeException('目标模块标识格式错误');
        }
        $root = self::repositoryRoot();
        $matches = [];
        foreach (glob($root . '/server/app/modules/*/*/module.json') ?: [] as $manifestAbsolute) {
            $manifestPath = substr($manifestAbsolute, strlen($root) + 1);
            $source = self::readFile($manifestPath);
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
            $composer = json_decode(self::readFile($backendRelative . '/composer.json'), true, 64, JSON_THROW_ON_ERROR);
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
            'permissionsSource' => self::readFile($permissionsPath),
            'routePath' => $routePath,
            'routeSource' => self::readFile($routePath),
            'frontendContributionPath' => $frontendEntry,
            'frontendContributionSource' => self::readFile($frontendEntry),
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
            ? 'require|integer|gt:0'
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
    private static function relations(mixed $rawRelations, array $defaultModule, string $edition): array
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
            $relatedModule = self::module((string) ($raw['module'] ?? $defaultModule['key']));
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
{{softBody}}    protected $name = '{{tableName}}';
    protected $pk = '{{primary}}';
{{relationMethods}}}
PHP, $c + compact('softImport', 'softBody') + ['relationMethods' => $relations]);
    }

    private static function renderController(array $c): string
    {
        $listResponse = $c['tree'] === [] ? 'return $this->dataLists($result);' : 'return $this->data($result);';
        $softDeleteDeclaration = $c['softDelete'] ? "    protected const CRUD_SOFT_DELETE = true;\n" : '';
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace {{namespace}}Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\PageResult;
use app\common\traits\CrudTrait;
use {{namespace}}Service\{{entity}}Service;
use {{namespace}}Validation\{{entity}}Validate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

/** @property-read {{entity}}Service $crud 当前 App 中的 {{title}} CRUD 用例。 */
final class {{entity}}Controller extends BaseAdminController
{
    use CrudTrait;

    protected string $crudClass = {{entity}}Service::class;
    protected const CRUD_VALIDATE = {{entity}}Validate::class;
    protected const CRUD_VALIDATE_LISTS = true;
{{softDeleteDeclaration}}    protected const CRUD_PRIMARY_KEY = '{{primary}}';
    protected const CRUD_PRIMARY_KEY_TYPE = '{{primaryType}}';
    protected const CRUD_INPUT_FIELDS = {{inputFields}};
    protected const CRUD_WRITABLE_FIELDS = {{writableFields}};

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function renderLists(PageResult|array $result): Json
    {
        {{listResponse}}
    }
}
PHP, $c + self::controllerFieldPolicies($c) + compact('listResponse', 'softDeleteDeclaration'));
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
        $detailLookup = "        \$model = {$c['entity']}::where('{$c['primary']}', \$id){$withCode}->findOrEmpty();\n"
            . "        if (\$model->isEmpty()) {\n"
            . "            throw BusinessException::notFound('{$c['errorPrefix']}_NOT_FOUND', '{$c['title']}不存在');\n"
            . "        }\n"
            . "        return \$this->project(\$model, self::DETAIL_FIELDS);";
        if ($c['tree'] !== []) {
            $listBody = "        \$rows = \$query{$withCode}->order(['{$c['primary']}' => 'desc'])->select()\n"
                . "            ->map(fn(\$item): array => \$this->project(\$item, self::LIST_FIELDS))->toArray();\n"
                . "        return linear_to_tree(\$rows, 'children', '{$c['primary']}', '{$c['tree']['parent']}');";
            $childGuard = "            if ({$c['entity']}::where('{$c['tree']['parent']}', \$id)->count() > 0) {\n"
                . "                throw BusinessException::conflict('{$c['errorPrefix']}_HAS_CHILDREN', '请先删除下级节点');\n            }\n";
        } else {
            $listBody = "        \$page = PaginationInput::from(\$params)->result(\$query{$withCode}->order(['{$c['primary']}' => 'desc']));\n"
                . "        return \$page->map(fn(\$item): array => \$this->project(\$item, self::LIST_FIELDS));";
            $childGuard = '';
        }
        $softImport = $c['softDelete'] ? "\nuse think\\db\\exception\\PDOException;" : '';
        $softMethods = self::renderSoftDeleteService($c, $queryCode, $listBody);
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
use {{namespace}}Model\{{entity}};
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;
use think\Model;
{{softImport}}

class {{entity}}Service
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

    public function lists(TenantContext $context, array $params): array|PageResult
    {
        $this->assertPermission($context, '{{permissionPrefix}}.list');
        $query = {{entity}}::where([]);
{{queryCode}}{{listBody}}
    }

    public function detail(TenantContext $context, int|string $id): array
    {
        $this->assertPermission($context, '{{permissionPrefix}}.detail');
{{detailLookup}}
    }

    public function add(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, '{{permissionPrefix}}.add');
        $model = new {{entity}}();
        if ($model->save(array_intersect_key($params, array_flip(self::INSERT_FIELDS))) === false) {
            throw BusinessException::invalid('{{errorPrefix}}_SAVE_FAILED', '保存失败，请稍后重试');
        }
        return true;
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, '{{permissionPrefix}}.edit');
        $this->mutate($params['{{primary}}'], function ({{entity}} $model) use ($params): void {
            if ($model->save(array_intersect_key($params, array_flip(self::UPDATE_FIELDS))) === false) {
                throw BusinessException::invalid('{{errorPrefix}}_SAVE_FAILED', '保存失败，请稍后重试');
            }
        });
        return true;
    }

    public function delete(TenantContext $context, int|string $id): bool
    {
        $this->assertPermission($context, '{{permissionPrefix}}.delete');
        $this->mutate($id, function ({{entity}} $model) use ($id): void {
{{childGuard}}            if ($model->delete() === false) {
                throw BusinessException::invalid('{{errorPrefix}}_DELETE_FAILED', '删除失败，请稍后重试');
            }
        });
        return true;
    }

{{softMethods}}

    public function updateStatus(TenantContext $context, int|string $id, int $status): bool
    {
        throw BusinessException::invalid('{{errorPrefix}}_STATUS_UNSUPPORTED', '该资源未声明统一状态字段');
    }

    private function mutate(int|string $id, callable $callback): void
    {
        Db::transaction(function () use ($id, $callback): void {
            $callback($this->find($id, true));
        });
    }

    private function find(int|string $id, bool $lock = false): {{entity}}
    {
        $query = {{entity}}::where('{{primary}}', $id);
        if ($lock) $query->lock(true);
        $model = $query->findOrEmpty();
        if ($model->isEmpty()) {
            throw BusinessException::notFound('{{errorPrefix}}_NOT_FOUND', '{{title}}不存在');
        }
        return $model;
    }

    /** 公开输出只取声明字段；关联只返回明确摘要。 */
    private function project(Model|array $record, array $fields): array
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
    private function assertPermission(TenantContext $context, string $permission): void
    {
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
            'childGuard' => $childGuard,
            'listFieldsExport' => var_export($c['listFields'], true),
            'detailFieldsExport' => var_export($c['detailFields'], true),
            'relationSummaries' => var_export(array_column($c['relations'], 'summaryFields', 'name'), true),
            'detailLookup' => $detailLookup,
            'softImport' => $softImport,
            'softMethods' => $softMethods,
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
        return $this->batch($ids, 'restored', function (int|string $id): string {
            return Db::transaction(function () use ($id): string {
                $model = {{entity}}::withTrashed()->where('{{primary}}', $id)->lock(true)->findOrEmpty();
                if ($model->isEmpty()) {
                    throw BusinessException::notFound('{{errorPrefix}}_NOT_FOUND', '{{title}}不存在');
                }
                if (!$model->trashed()) return 'already_active';
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
                return 'restored';
            });
        });
    }

    public function purge(TenantContext $context, array $ids): array
    {
        $this->assertPermission($context, '{{permissionPrefix}}.purge');
        return $this->batch($ids, 'purged', function (int|string $id): string {
            return Db::transaction(function () use ($id): string {
                $model = {{entity}}::withTrashed()->where('{{primary}}', $id)->lock(true)->findOrEmpty();
                if ($model->isEmpty()) {
                    throw BusinessException::notFound('{{errorPrefix}}_NOT_FOUND', '{{title}}不存在');
                }
                if (!$model->trashed()) {
                    throw BusinessException::conflict('{{errorPrefix}}_PURGE_REQUIRES_TRASHED', '仅回收站记录可永久删除');
                }
                $this->assertPurgeRelations($model);
                if ($model->force(true)->delete() === false) throw new \LogicException('{{errorPrefix}}_PURGE_FAILED');
                return 'purged';
            });
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
        $result = ['requested' => $ids, $successKey => [], 'already_active' => [], 'failed' => []];
        foreach ($ids as $id) {
            try {
                $result[$operation($id)][] = $id;
            } catch (BusinessException $exception) {
                $result['failed'][] = ['{{primary}}' => $id, 'code' => $exception->errorCode, 'message' => $exception->getMessage()];
            }
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
                $parts[] = $column['rule'];
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
        $rules['page_no'] = 'integer|gt:0';
        $rules['page_size'] = 'integer|gt:0|elt:100';
        if ($c['softDelete']) {
            $rules['ids'] = 'array|checkIds';
        }
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
            ? "            if (filter_var(\$id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) return '操作对象主键格式错误';"
            : "            if ((!is_string(\$id) && !is_int(\$id)) || trim((string)\$id) === ''"
                . ($c['primaryLength'] > 0 ? " || mb_strlen(trim((string)\$id)) > {$c['primaryLength']}" : '')
                . ") return '操作对象主键格式错误';";
        $softSceneMethods = $c['softDelete'] ? self::replace(<<<'PHP'

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
        return self::replace(<<<'PHP'
<?php
declare(strict_types=1);

namespace {{namespace}}Validation;

use think\Validate;

final class {{entity}}Validate extends Validate
{
    protected $rule = {{rules}};
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
{{softSceneMethods}}}
PHP, array_merge($c, $policies, [
            'rules' => var_export($rules, true),
            'messages' => var_export($messages, true),
            'editSceneFields' => var_export([$c['primary'], ...$policies['updateFieldsArray']], true),
            'listRemovals' => $listRemovals,
            'softScenes' => $softScenes,
            'softSceneMethods' => $softSceneMethods,
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

export function get{{entity}}RecycleList(params: {{entity}}ListParams = {}) {
  return axios.get<{{entity}}ListResult>('/adminapi/{{permissionPrefix}}.recycle.list', { params });
}

export function get{{entity}}RecycleDetail({{primary}}: {{primaryTsType}}) {
  return axios.get<{{entity}}Detail>('/adminapi/{{permissionPrefix}}.recycle.detail', { params: { {{primary}} } });
}

export function restore{{entity}}(ids: {{primaryTsType}}[]) {
  return axios.post<{{entity}}BatchResult>('/adminapi/{{permissionPrefix}}.restore', { ids });
}

export function purge{{entity}}(ids: {{primaryTsType}}[]) {
  return axios.post<{{entity}}BatchResult>('/adminapi/{{permissionPrefix}}.purge', { ids });
}
TS, $c) : '';
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

export interface {{entity}}ListResult {
  lists: {{entity}}ListRecord[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface {{entity}}ListParams {
{{listParamFields}}}
{{softTypes}}

export function get{{entity}}List(params: {{entity}}ListParams = {}) {
  return axios.get<{{entity}}ListResult>('/adminapi/{{permissionPrefix}}.list', { params });
}

export function get{{entity}}Detail({{primary}}: {{primaryTsType}}) {
  return axios.get<{{entity}}Detail>('/adminapi/{{permissionPrefix}}.detail', { params: { {{primary}} } });
}

export function add{{entity}}(data: {{entity}}CreateInput) {
  return axios.post('/adminapi/{{permissionPrefix}}.add', data);
}

export function edit{{entity}}(data: {{entity}}UpdateInput) {
  return axios.post('/adminapi/{{permissionPrefix}}.edit', data);
}

export function delete{{entity}}({{primary}}: {{primaryTsType}}) {
  return axios.post('/adminapi/{{permissionPrefix}}.delete', { {{primary}} });
}
{{softFunctions}}
TS, $c + [
            'listTypeFields' => self::typescriptFields($c, $c['listFields']),
            'detailTypeFields' => self::typescriptFields($c, $c['detailFields']),
            'createTypeFields' => self::typescriptFields($c, $policies['insertFieldsArray'], false),
            'updateTypeFields' => self::typescriptFields($c, [$c['primary'], ...$policies['updateFieldsArray']], false),
            'listParamFields' => self::typescriptQueryFields($c, $policies['listFieldsArray']),
            'softTypes' => $softTypes,
            'softFunctions' => $softFunctions,
        ]);
    }

    private static function renderView(array $c): string
    {
        $visible = array_values(array_filter($c['columns'], static fn(array $column): bool => in_array($column['name'], $c['listFields'], true)));
        $columns = '';
        foreach (array_slice($visible, 0, 8) as $column) {
            $columns .= "    { label: '{$column['comment']}', prop: '{$column['name']}' },\n";
        }
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
        $uiImports = 'ElAlert, ElButton, ElCard, ElPagination, ElTable, ElTableColumn, vLoading'
            . ($c['softDelete'] ? ', ElMessage, ElPopconfirm' : '');
        $asyncImports = $c['softDelete'] ? 'useAsyncList, useAsyncAction' : 'useAsyncList';
        $filterType = $c['softDelete'] ? '{ recycle: boolean }' : 'Record<string, never>';
        $initialFilters = $c['softDelete'] ? '{ recycle: false }' : '{}';
        $filterParameter = $c['softDelete'] ? ', filters' : '';
        $fetchCall = $c['softDelete']
            ? "(filters.recycle ? get{$c['entity']}RecycleList : get{$c['entity']}List)"
            : "get{$c['entity']}List";
        $disabledPagination = $c['softDelete'] ? ' :disabled="actionLoading"' : '';
        $softHandlers = $c['softDelete'] ? self::replace(<<<'TS'

  const showRecycle = computed(() => list.filters.value.recycle);
  const action = useAsyncAction(() => '操作失败，请重试');
  const { loading: actionLoading, error: actionError } = action;
  const toggleRecycle = () => {
    if (actionLoading.value) return;
    action.cancel();
    list.clear();
    list.filters.value.recycle = !showRecycle.value;
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
  const handleRestore = async (record: unknown) => {
    const result = await action.run(async () => {
      const response = await restore{{entity}}([readPrimaryKey(record)]);
      if (!Array.isArray(response.data.failed) || response.data.failed.length !== 0) throw new Error('RESTORE_INCOMPLETE');
    });
    if (result.status !== 'completed') return;
    ElMessage.success('恢复成功');
    await fetchData(pagination.page);
  };
  const handlePurge = async (record: unknown) => {
    const result = await action.run(async () => {
      const response = await purge{{entity}}([readPrimaryKey(record)]);
      if (!Array.isArray(response.data.failed) || response.data.failed.length !== 0) throw new Error('PURGE_INCOMPLETE');
    });
    if (result.status !== 'completed') return;
    ElMessage.success('永久删除成功');
    await fetchData(pagination.page);
  };
TS, $c) : '';
        return self::replace(<<<'VUE'
<template>
  <ElCard class="general-card" header="{{title}}">
{{recycleToggle}}    <ElAlert v-if="error" :title="error" type="error" :closable="false" />
    <ElButton v-if="error" :disabled="loading" @click="fetchData(pagination.page)">重新加载</ElButton>
    <ElTable v-loading="loading" row-key="{{primary}}" :data="records">
      <ElTableColumn
        v-for="column in columns"
        :key="column.prop"
        :prop="column.prop"
        :label="column.label"
      />
{{actionSlot}}      <template #empty>
        <span role="status">{{ loading ? '正在加载…' : error ? '加载失败' : '暂无数据' }}</span>
      </template>
    </ElTable>
    <ElPagination
      :current-page="pagination.page"
      :page-size="pagination.pageSize"
      :total="pagination.total"{{disabledPagination}}
      layout="total, prev, pager, next"
      @current-change="fetchData"
    />
  </ElCard>
</template>

<script setup lang="ts">
  import { computed } from 'vue';
  import { {{uiImports}} } from 'element-plus';
  import { {{asyncImports}} } from '@peanut-admin/vue';
  import { {{apiImports}} } from './api';

  interface ListColumn {
    label: string;
    prop: keyof {{entity}}ListRecord;
  }
  const columns: ListColumn[] = [
{{tableColumns}}  ];
  // The public composable isolates late requests and disposes its scope; no page-local request engine.
  const list = useAsyncList<{{entity}}ListRecord, {{filterType}}>({
    initialFilters: {{initialFilters}},
    initialPageSize: 15,
    load: async ({ page, pageSize{{filterParameter}} }) => {
      const response = await {{fetchCall}}({ page_no: page, page_size: pageSize });
      return { items: response.data.lists, total: response.data.count, page, pageSize };
    },
    errorMessage: () => '列表加载失败，请重试',
  });
  const { items, pagination, loading, error, load: fetchData } = list;
  const records = computed(() => [...items.value]);
{{softHandlers}}  void fetchData();
</script>
VUE, $c + compact(
            'recycleToggle',
            'actionSlot',
            'apiImports',
            'uiImports',
            'asyncImports',
            'filterType',
            'initialFilters',
            'filterParameter',
            'fetchCall',
            'disabledPagination',
            'softHandlers',
        ) + ['tableColumns' => $columns]);
    }

    private static function renderFrontendContribution(array $c): string
    {
        return self::replace(<<<'TS'
import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
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
{{softRoutes}}})->middleware([
    LoginMiddleware::class,
    [OfficialModuleMiddleware::class, ['{{moduleKey}}', 'http.admin']],
    AuthMiddleware::class,
    OperationLogMiddleware::class,
]);
PHP, $c + compact('softRoutes'));
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

    private static function mergeHostRouteRegistry(array $c): string
    {
        // The host also has an inline optional-module loop. Only the multiline registry owns generated routes.
        $marker = "\n] as \$moduleRoute) {";
        if (substr_count($c['hostRouteSource'], $marker) !== 1) {
            throw new RuntimeException('管理端 Module 路由登记入口无法安全合并');
        }
        return str_replace(
            $marker,
            "\n    {$c['moduleRouteRegistration']}{$marker}",
            $c['hostRouteSource'],
        );
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
        return [
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
        return [
            'inputFields' => var_export($input, true),
            'writableFields' => var_export(['add' => $fields['insertFieldsArray'], 'edit' => $fields['updateFieldsArray']], true),
        ];
    }

    /** @param list<string> $fieldNames */
    private static function typescriptFields(array $c, array $fieldNames, bool $includeRelationSummaries = true): string
    {
        $byName = array_column($c['columns'], null, 'name');
        $result = '';
        foreach ($fieldNames as $name) {
            $column = $byName[$name] ?? null;
            if (!is_array($column)) {
                continue;
            }
            $result .= "  {$name}" . ($column['required'] || $column['primary'] ? '' : '?') . ": {$column['tsType']};\n";
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

    private static function repositoryRoot(): string
    {
        $root = realpath(dirname(__DIR__, 5));
        if ($root === false || !is_dir($root . '/server/app/modules')) {
            throw new RuntimeException('应用源码根目录不可用');
        }
        return $root;
    }

    private static function readFile(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new RuntimeException('模块登记路径无效');
        }
        $root = self::repositoryRoot();
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
            return 'integer';
        }
        if (in_array($type, ['decimal', 'float', 'double'], true)) {
            return 'float';
        }
        if ($type === 'array' || $type === 'json') {
            return 'array';
        }
        return $length > 0 ? 'max:' . $length : 'string';
    }
}
