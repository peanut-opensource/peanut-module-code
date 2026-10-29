<?php

declare(strict_types=1);

namespace app\adminapi\validate\generator;

use think\Validate;

class GeneratorValidate extends Validate
{
    protected $rule = [
        'id'            => 'require|integer|gt:0',
        'ids'           => 'require|array|checkIds',
        'table_names'   => 'require|array|checkTableNames',
        'keyword'       => 'max:100',
        'page_no'       => 'integer|gt:0',
        'page_size'     => 'integer|between:1,100',
        'table_comment' => 'require|max:300',
        'module_name'   => 'require|regex:/^[a-z][a-z0-9_-]{0,31}(?:\.[a-z][a-z0-9-]{0,31})?$/',
        'entity_name'   => 'require|regex:/^[A-Z][A-Za-z0-9]{0,63}$/',
        'template_type' => 'require|in:crud,tree',
        'data_owner'    => 'require|in:tenant-orm,platform,instance,shared',
        'target_edition' => 'require|in:standalone,multi-tenant',
        'author'        => 'max:100',
        'tree_config'   => 'array',
        'soft_delete'   => 'require|array',
        'relations'     => 'array',
        'columns'       => 'require|array|checkColumns',
        'token'         => 'require|regex:/^[a-f0-9]{64}$/',
    ];

    protected $message = [
        'id.require' => '生成配置 ID 不能为空',
        'ids.require' => '请选择生成配置',
        'table_names.require' => '请选择数据表',
        'table_comment.require' => '数据表说明不能为空',
        'module_name.require' => '模块名称不能为空',
        'module_name.regex' => '模块名称格式错误',
        'entity_name.require' => '实体名称不能为空',
        'entity_name.regex' => '实体名称格式错误',
        'template_type.require' => '模板类型不能为空',
        'template_type.in' => '模板类型错误',
        'data_owner.require' => '数据所有权不能为空',
        'data_owner.in' => '数据所有权错误',
        'target_edition.require' => '目标 Edition 不能为空',
        'target_edition.in' => '目标 Edition 错误',
        'columns.require' => '字段配置不能为空',
        'token.require' => '下载令牌不能为空',
        'token.regex' => '下载令牌格式错误',
    ];

    protected $scene = [
        'source' => ['keyword', 'page_no', 'page_size'],
        'lists' => ['keyword', 'page_no', 'page_size'],
        'import' => ['table_names'],
        'id' => ['id'],
        'ids' => ['ids'],
        'update' => [
            'id', 'table_comment', 'module_name', 'entity_name', 'template_type',
            'data_owner', 'target_edition', 'author', 'tree_config', 'soft_delete', 'relations', 'columns',
        ],
        'download' => ['token'],
    ];

    protected function checkIds(mixed $value): bool|string
    {
        if (!is_array($value) || $value === [] || count($value) > 20) {
            return '生成配置数量须在 1 到 20 之间';
        }
        foreach ($value as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return '生成配置 ID 格式错误';
            }
        }
        return true;
    }

    protected function checkTableNames(mixed $value): bool|string
    {
        if (!is_array($value) || $value === [] || count($value) > 20) {
            return '数据表数量须在 1 到 20 之间';
        }
        foreach ($value as $name) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $name)) {
                return '数据表名称格式错误';
            }
        }
        return true;
    }

    protected function checkColumns(mixed $value): bool|string
    {
        if (!is_array($value) || $value === [] || count($value) > 300) {
            return '字段配置格式错误';
        }
        foreach ($value as $column) {
            if (!is_array($column) || empty($column['id'])) {
                return '字段配置 ID 缺失';
            }
        }
        return true;
    }
}
