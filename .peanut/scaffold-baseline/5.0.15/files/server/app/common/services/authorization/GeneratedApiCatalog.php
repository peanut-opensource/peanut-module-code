<?php

declare(strict_types=1);

namespace app\common\services\authorization;

/** Shared reader for the route generator's source-bound artifact. */
final class GeneratedApiCatalog
{
    public static function load(string $serverRoot): array
    {
        $path = $serverRoot . '/generated/api-catalog.json';
        if (!is_file($path) || is_link($path)) {
            throw new \DomainException('API_CATALOG_UNAVAILABLE');
        }
        $document = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        $freshness = self::freshness($serverRoot, $document['inputs'] ?? null);
        if ($freshness !== null) {
            throw new \DomainException($freshness);
        }
        if (($document['schema_version'] ?? null) !== 1 || !is_array($document['endpoints'] ?? null) || !array_is_list($document['endpoints'])) {
            throw new \DomainException('API_CATALOG_INVALID');
        }
        return $document;
    }

    public static function freshness(string $serverRoot, mixed $inputs): ?string
    {
        if (!is_array($inputs) || !array_is_list($inputs) || $inputs === []) {
            return 'The generated catalog does not register its source hashes. Regenerate it before using its routes.';
        }
        $projectRoot = dirname($serverRoot);
        $previous = null;
        $release = file_exists($serverRoot . '/.peanut/release-identity.json') || is_link($serverRoot . '/.peanut/release-identity.json');
        foreach ($inputs as $input) {
            if (!is_array($input) || array_keys($input) !== ['path', 'sha256']) {
                return 'The generated catalog input registry is invalid. Regenerate it.';
            }
            $relative = $input['path'] ?? null;
            $expected = $input['sha256'] ?? null;
            if (!is_string($relative)
                || (!in_array($relative, ['release-versions.json', 'plugins.lock'], true) && !str_starts_with($relative, 'server/'))
                || str_starts_with($relative, '/') || str_contains($relative, '\\')
                || array_intersect(explode('/', $relative), ['', '.', '..']) !== []
                || preg_match('/[\x00-\x1f]/', $relative) === 1
                || !is_string($expected) || preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1
                || ($previous !== null && strcmp($previous, $relative) >= 0)) {
                return 'The generated catalog input registry is invalid or not canonically sorted. Regenerate it.';
            }
            $sourceRelative = $relative;
            if ($release && $relative === 'release-versions.json') {
                $sourceRelative = 'server/.peanut/release-versions.json';
            } elseif ($release && $relative === 'plugins.lock') {
                // Server releases project Module roots; the verified identity binds
                // that projection to the original APP lock used by this catalogue.
                $projection = \app\common\value\installation\ServerReleaseIdentity::resolve($serverRoot)->document()['plugin_projection'];
                if (!hash_equals($expected, $projection['source_lock_sha256'])) {
                    return 'The API catalogue Plugin source lock differs from the verified server release.';
                }
                $expected = $projection['projected_lock_sha256'];
                $sourceRelative = 'server/plugins.lock';
            }
            $cursor = $projectRoot;
            foreach (explode('/', $sourceRelative) as $part) {
                $cursor .= '/' . $part;
                if (is_link($cursor)) {
                    return 'A registered API source is linked: ' . $relative . '. Regenerate the catalog.';
                }
            }
            $source = $projectRoot . '/' . $sourceRelative;
            $actual = is_file($source) && !is_link($source) ? hash_file('sha256', $source) : false;
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                return 'A registered API source changed or disappeared: ' . $relative . '. Regenerate the catalog.';
            }
            $previous = $relative;
        }
        return null;
    }
}
