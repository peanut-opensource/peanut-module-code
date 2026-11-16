<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\access\SourceRead;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

/**
 * 将同一来源的对象／字段授权整理为一次查询可以安全采用的投影。
 * 指定字段时只纳入获准全部这些字段的对象；不同对象的字段不会互相授权。
 * 未指定字段保留“所有获准对象的共同字段”模式，但不把它冒充逐对象投影。
 */
final class SourceReadProjection
{
    /** @param list<string> $requested @param list<string> $known @return list<string> */
    public static function requestedFields(array $requested, array $known): array
    {
        if (!array_is_list($requested)) {
            throw new DataAuthorizationException('AUTHZ_READ_FIELDS_DENIED', 'The output projection is invalid.');
        }
        $normalized = [];
        foreach ($requested as $field) {
            if (!is_string($field) || !in_array($field, $known, true) || isset($normalized[$field])) {
                throw new DataAuthorizationException('AUTHZ_READ_FIELDS_DENIED', 'The output field is not registered or is duplicated.');
            }
            $normalized[$field] = true;
        }
        $fields = array_keys($normalized);
        sort($fields, SORT_STRING);
        return $fields;
    }

    /**
     * @param list<array{object_id:?int,fields:list<string>}> $allows
     * @param list<int> $denied
     * @param list<string> $known
     * @param list<string> $requested
     * @return array{0:?array,1:list<string>}
     */
    public static function resolve(array $allows, array $denied, array $known, array $requested): array
    {
        $requested = self::requestedFields($requested, $known);
        $global = [];
        $objects = [];
        foreach ($allows as $allow) {
            $fields = array_values(array_intersect($known, $allow['fields']));
            if ($fields === []) {
                continue;
            }
            if ($allow['object_id'] === null) {
                $global = array_values(array_unique([...$global, ...$fields]));
            } elseif (!in_array($allow['object_id'], $denied, true)) {
                $id = $allow['object_id'];
                $objects[$id] = array_values(array_unique([...($objects[$id] ?? []), ...$fields]));
            }
        }

        if ($requested !== []) {
            if (array_diff($requested, $global) === []) {
                // 全对象许可已经包含全部所需字段；对象级允许不能反向缩窄它。
                return [null, $requested];
            }
            $ids = [];
            foreach ($objects as $id => $fields) {
                if (array_diff($requested, [...$global, ...$fields]) === []) {
                    $ids[] = (int) $id;
                }
            }
            if ($ids === []) {
                throw new DataAuthorizationException('AUTHZ_READ_FIELDS_DENIED', 'No object is granted the requested output projection.');
            }
            sort($ids, SORT_NUMERIC);
            return [$ids, $requested];
        }

        if ($global !== []) {
            sort($global, SORT_STRING);
            return [null, $global];
        }
        if ($objects === []) {
            throw new DataAuthorizationException('AUTHZ_READ_SOURCE_DENIED', 'No authorized object remains in the source.');
        }
        $fields = null;
        foreach ($objects as $projection) {
            $fields = $fields === null ? $projection : array_values(array_intersect($fields, $projection));
        }
        if ($fields === null || $fields === []) {
            throw new DataAuthorizationException('AUTHZ_READ_FIELDS_DENIED', 'Select an explicit projection for object-specific grants.');
        }
        $ids = array_map('intval', array_keys($objects));
        sort($ids, SORT_NUMERIC);
        sort($fields, SORT_STRING);
        return [$ids, $fields];
    }

    private function __construct() {}
}
