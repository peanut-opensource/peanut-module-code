<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Infrastructure;

use RuntimeException;

final class BrandDefaults
{
    /** @return array<string, string> */
    public static function website(): array
    {
        return self::stringMap(self::manifest()['website'] ?? null, '品牌默认配置格式错误');
    }

    /** @return array<string, string> */
    public static function defaultImages(): array
    {
        return self::stringMap(self::manifest()['default_image'] ?? null, '品牌默认图片配置格式错误');
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $path = dirname(__DIR__, 6) . '/config/brand.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('无法读取品牌默认配置');
        }

        $manifest = json_decode($json, true);
        if (!is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('品牌默认配置格式错误');
        }
        return $manifest;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $values, string $error): array
    {
        if (!is_array($values)) {
            throw new RuntimeException($error);
        }
        $result = [];
        foreach ($values as $field => $value) {
            if (!is_string($field) || !is_string($value)) {
                throw new RuntimeException($error);
            }
            $result[$field] = $value;
        }
        return $result;
    }
}
