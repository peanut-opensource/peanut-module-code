<?php

declare(strict_types=1);

namespace app\platform\services\module;

use Composer\Semver\Comparator;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use app\platform\exception\module\ModulePackageException;

/** Official transport/catalog adapter. Native Module admission remains the sole installer. */
final class OfficialModuleReleaseService
{
    private const MODULE_REPO = 'peanut-opensource/peanut-module-code';
    private const SCAFFOLD_REPO = 'peanut-business/peanut-admin-code';
    private const INDEX = 'module-release-index.json';
    private const TAG = '/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.(0|[1-9][0-9]*))?$/D';
    private const KEY = '/^peanut\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';
    private const SHA = '/^[a-f0-9]{64}$/D';
    private const SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    private array $catalogs = [];
    private array $releases = [];

    public function __construct(private readonly string $projectRoot, private readonly ClientInterface $http) {}

    /** Source lock versions are not misrepresented as successful runtime installation. */
    public function updates(string $channel = 'stable'): array
    {
        $this->channel($channel);
        $application = $this->local('.peanut/application-manifest.json');
        $edition = $application['edition']['name'] ?? null;
        $this->ensure(in_array($edition, ['standalone', 'multi-tenant'], true), 'APPLICATION_IDENTITY_INVALID');
        $lock = $this->local('modules.lock');
        $this->ensure(($lock['schema_version'] ?? null) === 1 && is_array($lock['modules'] ?? null), 'SOURCE_LOCK_INVALID');
        $current = [];
        foreach ($lock['modules'] as $row) {
            $this->ensure(is_array($row) && is_string($row['key'] ?? null), 'SOURCE_LOCK_INVALID');
            $key = $row['key'];
            if (preg_match(self::KEY, $key) !== 1) {
                continue;
            }
            $this->ensure(!isset($current[$key]) && $this->version($row['version'] ?? null), 'SOURCE_LOCK_INVALID');
            $current[$key] = $row['version'];
        }
        $latest = [];
        $identities = [];
        foreach ($this->listReleases(self::MODULE_REPO) as $release) {
            if (($release['draft'] ?? null) !== false || empty($release['published_at'])
                || !in_array(self::INDEX, array_column($release['assets'] ?? [], 'name'), true)) {
                continue;
            }
            $index = $this->catalog($release);
            foreach ($index['modules'] as $row) {
                $identity = $row['key'] . '@' . $row['version'];
                $this->ensure(!isset($identities[$identity]) || $this->identity($row) === $identities[$identity], 'VERSION_IDENTITY_CONFLICT');
                $identities[$identity] = $this->identity($row);
                if (!isset($current[$row['key']]) || !in_array($edition, $row['editions'], true)
                    || ($channel === 'stable' && ($release['prerelease'] || str_contains(explode('+', $row['version'])[0], '-')))) {
                    continue;
                }
                if (!isset($latest[$row['key']]) || Comparator::greaterThan($row['version'], $latest[$row['key']]['version'])) {
                    $latest[$row['key']] = $row + ['catalog_tag' => $index['tag']];
                }
            }
        }
        ksort($current, SORT_STRING);
        $modules = [];
        foreach ($current as $key => $version) {
            $target = $latest[$key] ?? null;
            $status = $target === null ? 'no_published_candidate'
                : (Comparator::greaterThan($target['version'], $version) ? 'update_available'
                    : (Comparator::equalTo($target['version'], $version) ? 'current' : 'source_newer'));
            $modules[] = ['key' => $key, 'source_version' => $version,
                'available_version' => $target['version'] ?? null, 'sha256' => $target['sha256'] ?? null,
                'catalog_tag' => $target['catalog_tag'] ?? null, 'status' => $status,
                'compatibility' => 'native_preflight_required'];
        }
        $scaffold = ['current_version' => $application['template']['version'] ?? null,
            'available_version' => null, 'status' => 'no_published_candidate', 'upgrade_method' => 'native_cli_plan'];
        $this->ensure($this->version($scaffold['current_version']), 'APPLICATION_IDENTITY_INVALID');
        foreach ($this->listReleases(self::SCAFFOLD_REPO) as $release) {
            $tag = $release['tag_name'] ?? '';
            if (($release['draft'] ?? null) !== false || empty($release['published_at'])
                || !is_string($tag) || preg_match(self::TAG, $tag) !== 1
                || ($channel === 'stable' && (($release['prerelease'] ?? true) || str_contains($tag, '-')))
                || explode('.', substr($tag, 1))[0] !== explode('.', $scaffold['current_version'])[0]) {
                continue;
            }
            $this->releaseIdentity($release, self::SCAFFOLD_REPO);
            $candidate = substr($tag, 1);
            if ($scaffold['available_version'] === null || Comparator::greaterThan($candidate, $scaffold['available_version'])) {
                $scaffold['available_version'] = $candidate;
            }
        }
        if ($scaffold['available_version'] !== null) {
            $scaffold['status'] = Comparator::greaterThan($scaffold['available_version'], $scaffold['current_version']) ? 'update_available' : 'current';
        }
        return ['schema_version' => 1, 'channel' => $channel, 'edition' => $edition,
            'modules' => $modules, 'scaffold' => $scaffold, 'changed' => false,
            'scope' => 'published version discovery and source lock; runtime admission and tests not run'];
    }

    /** Exact reviewed identity, never a caller-selected URL, filesystem path or shell command. */
    public function package(array $selection): array
    {
        $this->ensure(array_diff(array_keys($selection), ['module_key', 'version', 'sha256', 'catalog_tag', 'channel']) === [], 'SELECTION_INVALID');
        $key = $selection['module_key'] ?? null;
        $version = $selection['version'] ?? null;
        $sha = $selection['sha256'] ?? null;
        $tag = $selection['catalog_tag'] ?? null;
        $channel = $selection['channel'] ?? 'stable';
        $this->channel($channel);
        $this->ensure(is_string($key) && preg_match(self::KEY, $key) === 1 && $this->version($version)
            && is_string($sha) && preg_match(self::SHA, $sha) === 1
            && is_string($tag) && preg_match(self::TAG, $tag) === 1, 'SELECTION_INVALID');
        $release = $this->release($tag);
        $index = $this->catalog($release);
        $this->ensure($channel === 'prerelease' || (!$release['prerelease'] && !str_contains(explode('+', $version)[0], '-')), 'PRERELEASE_OPT_IN_REQUIRED');
        $matches = array_values(array_filter($index['modules'], static fn(array $row): bool => $row['key'] === $key));
        $this->ensure(count($matches) === 1 && $matches[0]['version'] === $version && $matches[0]['sha256'] === $sha, 'SELECTION_CHANGED');
        $row = $matches[0];
        $originTag = $row['artifact_tag'] ?? $tag;
        if ($originTag !== $tag) {
            $this->ensure(($release['immutable'] ?? null) === true, 'IMMUTABLE_RELEASE_REQUIRED');
            $origin = $this->release($originTag);
            $this->ensure(($origin['immutable'] ?? null) === true, 'IMMUTABLE_RELEASE_REQUIRED');
            $originals = array_values(array_filter($this->catalog($origin)['modules'], static fn(array $item): bool => $item['key'] === $key));
            $this->ensure(count($originals) === 1 && ($originals[0]['artifact_tag'] ?? $originTag) === $originTag
                && $this->identity($originals[0]) === $this->identity($row), 'REFERENCE_IDENTITY_INVALID');
            $release = $origin;
        }
        $bytes = $this->asset($release, self::MODULE_REPO, $row['asset'], 67108864);
        $this->ensure(strlen($bytes) === $row['bytes'] && hash_equals($row['sha256'], hash('sha256', $bytes)), 'PACKAGE_DIGEST_MISMATCH');
        return ['bytes' => $bytes, 'sha256' => $sha, 'key' => $key, 'version' => $version];
    }

    private function identity(array $row): array
    {
        $identity = [];
        foreach (['key', 'version', 'asset', 'sha256', 'bytes', 'license', 'editions', 'kernel_constraint', 'dependencies'] as $field) {
            $identity[$field] = $row[$field];
        }
        return $identity;
    }

    private function release(string $tag): array
    {
        $this->ensure(preg_match(self::TAG, $tag) === 1, 'REFERENCE_TAG_INVALID');
        return $this->releases[$tag] ??= $this->json($this->request('https://api.github.com/repos/' . self::MODULE_REPO . '/releases/tags/' . rawurlencode($tag), 4194304));
    }

    private function catalog(array $release): array
    {
        $this->releaseIdentity($release, self::MODULE_REPO);
        $tag = $release['tag_name'];
        if (isset($this->catalogs[$tag])) {
            return $this->catalogs[$tag];
        }
        $index = $this->json($this->asset($release, self::MODULE_REPO, self::INDEX, 4194304));
        $schema = $index['schema_version'] ?? null;
        $this->ensure(in_array($schema, [1, 2], true) && ($index['protocol'] ?? null) === 'peanut.module-release-index.v' . $schema
            && ($index['repository'] ?? null) === self::MODULE_REPO && ($index['tag'] ?? null) === $tag
            && is_string($index['commit'] ?? null) && preg_match('/^[a-f0-9]{40}$/D', $index['commit']) === 1
            && is_array($index['modules'] ?? null) && array_is_list($index['modules'])
            && count($index['modules']) > 0 && count($index['modules']) <= 100, 'INDEX_INVALID');
        $this->ensure($schema !== 2 || ($release['immutable'] ?? null) === true, 'IMMUTABLE_RELEASE_REQUIRED');
        $seen = [];
        foreach ($index['modules'] as $row) {
            $this->ensure(is_array($row), 'INDEX_ROW_INVALID');
            $key = $row['key'] ?? null;
            $version = $row['version'] ?? null;
            $editions = $row['editions'] ?? null;
            $this->ensure(is_string($key) && preg_match(self::KEY, $key) === 1
                && !isset($seen[$key]) && $this->version($version)
                && ($row['asset'] ?? null) === $key . '-' . $version . '.tar'
                && is_string($row['sha256'] ?? null) && preg_match(self::SHA, $row['sha256']) === 1
                && is_int($row['bytes'] ?? null) && $row['bytes'] > 0 && $row['bytes'] <= 67108864
                && ($row['license'] ?? null) === 'Apache-2.0' && is_array($editions) && array_is_list($editions)
                && count($editions) > 0 && count($editions) <= 2
                && count(array_filter($editions, 'is_string')) === count($editions)
                && count(array_unique($editions)) === count($editions)
                && array_diff($editions, ['standalone', 'multi-tenant']) === []
                && is_string($row['kernel_constraint'] ?? null) && $row['kernel_constraint'] !== ''
                && is_array($row['dependencies'] ?? null) && array_is_list($row['dependencies']), 'INDEX_ROW_INVALID');
            foreach ($row['dependencies'] as $dependency) {
                $this->ensure(is_array($dependency) && is_string($dependency['module_key'] ?? null)
                    && preg_match(self::KEY, $dependency['module_key']) === 1
                    && is_string($dependency['version'] ?? null) && $dependency['version'] !== '', 'INDEX_DEPENDENCY_INVALID');
            }
            $this->ensure($schema === 2
                ? (is_string($row['artifact_tag'] ?? null) && preg_match(self::TAG, $row['artifact_tag']) === 1
                    && is_string($row['source_sha256'] ?? null) && preg_match(self::SHA, $row['source_sha256']) === 1)
                : !array_key_exists('artifact_tag', $row), 'INDEX_REFERENCE_INVALID');
            if (isset($row['payload_sha256'])) {
                $this->ensure(is_string($row['payload_sha256']) && preg_match(self::SHA, $row['payload_sha256']) === 1, 'INDEX_PAYLOAD_INVALID');
            }
            $seen[$key] = true;
        }
        return $this->catalogs[$tag] = $index;
    }

    private function listReleases(string $repository): array
    {
        $all = [];
        for ($page = 1; $page <= 20; $page++) {
            $rows = json_decode($this->request('https://api.github.com/repos/' . $repository . '/releases?per_page=100&page=' . $page, 8388608), true, 512, JSON_THROW_ON_ERROR);
            $this->ensure(is_array($rows) && array_is_list($rows) && count($rows) <= 100
                && count(array_filter($rows, 'is_array')) === count($rows), 'RELEASE_LIST_INVALID');
            array_push($all, ...$rows);
            if (count($rows) < 100) {
                return $all;
            }
        }
        throw new ModulePackageException('MODULE_RELEASE_DISCOVERY_LIMIT', 'Official release discovery was incomplete.');
    }

    private function releaseIdentity(array $release, string $repository): void
    {
        $tag = $release['tag_name'] ?? null;
        $this->ensure(is_string($tag) && preg_match(self::TAG, $tag) === 1
            && ($release['draft'] ?? null) === false && !empty($release['published_at'])
            && is_bool($release['prerelease'] ?? null) && is_int($release['id'] ?? null) && $release['id'] > 0
            && ($release['url'] ?? null) === 'https://api.github.com/repos/' . $repository . '/releases/' . $release['id']
            && ($release['html_url'] ?? null) === 'https://github.com/' . $repository . '/releases/tag/' . rawurlencode($tag)
            && is_array($release['assets'] ?? null), 'RELEASE_IDENTITY_INVALID');
    }

    private function asset(array $release, string $repository, string $name, int $limit): string
    {
        $this->releaseIdentity($release, $repository);
        $matches = array_values(array_filter($release['assets'], static fn($asset): bool => is_array($asset) && ($asset['name'] ?? null) === $name));
        $url = 'https://github.com/' . $repository . '/releases/download/' . rawurlencode($release['tag_name']) . '/' . rawurlencode($name);
        $this->ensure(count($matches) === 1 && ($matches[0]['browser_download_url'] ?? null) === $url
            && is_string($matches[0]['digest'] ?? null) && preg_match('/^sha256:[a-f0-9]{64}$/D', $matches[0]['digest']) === 1, 'ASSET_IDENTITY_INVALID');
        $data = $this->request($url, $limit);
        $this->ensure(hash_equals($matches[0]['digest'], 'sha256:' . hash('sha256', $data)), 'ASSET_DIGEST_MISMATCH');
        return $data;
    }

    private function request(string $url, int $limit): string
    {
        $response = $this->http->request('GET', $url, ['connect_timeout' => 10, 'timeout' => 35, 'read_timeout' => 35, 'stream' => true,
            'headers' => ['Accept' => str_starts_with($url, 'https://api.github.com/') ? 'application/vnd.github+json' : 'application/octet-stream', 'User-Agent' => 'Peanut-Developer-Updates'],
            'allow_redirects' => ['max' => 5, 'protocols' => ['https'], 'strict' => true,
                'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                    if (!in_array($uri->getHost(), ['github.com', 'api.github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)) {
                        throw new ModulePackageException('MODULE_RELEASE_REDIRECT_DENIED', 'Release redirect left the official distribution hosts.');
                    }
                }]]);
        $this->ensure($response->getStatusCode() === 200, 'DOWNLOAD_FAILED');
        $stream = $response->getBody();
        $data = '';
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($data)));
                $this->ensure($chunk !== '' || $stream->eof(), 'DOWNLOAD_STALLED');
                $data .= $chunk;
                $this->ensure(strlen($data) <= $limit, 'DOWNLOAD_TOO_LARGE');
            }
        } finally {
            $stream->close();
        }
        return $data;
    }

    private function local(string $relative): array
    {
        $file = $this->projectRoot . '/' . $relative;
        $this->ensure(is_file($file) && !is_link($file) && realpath($file) === $file, 'LOCAL_IDENTITY_MISSING');
        return $this->json((string) file_get_contents($file));
    }

    private function json(string $bytes): array
    {
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $this->ensure(is_array($value) && !array_is_list($value), 'RESPONSE_INVALID');
        return $value;
    }

    private function channel(mixed $channel): void
    {
        $this->ensure(in_array($channel, ['stable', 'prerelease'], true), 'CHANNEL_INVALID');
    }

    private function version(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SEMVER, $value) === 1;
    }

    private function ensure(bool $valid, string $code): void
    {
        if (!$valid) {
            throw new ModulePackageException('MODULE_RELEASE_' . $code, 'Official release verification failed: ' . $code);
        }
    }
}
