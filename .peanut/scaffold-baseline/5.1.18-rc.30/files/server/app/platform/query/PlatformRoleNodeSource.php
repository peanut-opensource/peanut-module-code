<?php

declare(strict_types=1);

namespace app\platform\query;

use app\common\services\authorization\GeneratedApiCatalog;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use think\facade\Db;

/**
 * Read-only Platform role source adapter. Official node definitions are emitted
 * from the existing Shell by the native API generator; role selections are not
 * allowed to supply their own node/API graph.
 *
 * This is NOT a direct-grant writer or a replacement authorization evaluator.
 */
final class PlatformRoleNodeSource
{
    private const SHELL_SOURCE = 'platform/src/core/platform-shell-definitions.ts';

    /** @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    public function authoritativeCandidate(array $candidate): array
    {
        $keys = array_keys($candidate);
        sort($keys, SORT_STRING);
        if ($keys !== ['role_assignments', 'role_ids']) {
            throw $this->invalid('PLATFORM_ROLE_CANDIDATE_INVALID');
        }
        $nodes = $this->currentNodes();
        $known = array_column($nodes, null, 'key');
        if (!is_array($candidate['role_assignments']) || !array_is_list($candidate['role_assignments'])) {
            throw $this->invalid('PLATFORM_ROLE_CANDIDATE_INVALID');
        }
        foreach ($candidate['role_assignments'] as $row) {
            if (!is_array($row) || !is_array($row['node_keys'] ?? null) || !array_is_list($row['node_keys'])) {
                throw $this->invalid('PLATFORM_ROLE_CANDIDATE_INVALID');
            }
            foreach ($row['node_keys'] as $key) {
                if (!is_string($key) || !isset($known[$key]) ||
                    $known[$key]['type'] === 'group' ||
                    ($known[$key]['type'] === 'page' && $known[$key]['api_ids'] === [])) {
                    throw $this->invalid('PLATFORM_ROLE_NODE_NOT_REGISTERED');
                }
            }
        }
        return ['role_ids' => $candidate['role_ids'], 'nodes' => $nodes,
            'role_assignments' => $candidate['role_assignments']];
    }

    /** @return list<array{key:string,parent_key:?string,type:string,api_ids:list<string>}> */
    private function currentNodes(): array
    {
        $serverRoot = dirname(__DIR__, 3);
        $sourcePath = dirname($serverRoot) . '/' . self::SHELL_SOURCE;
        $artifactPath = $serverRoot . '/generated/platform-role-source.json';
        if (!is_file($sourcePath) || is_link($sourcePath) || !is_file($artifactPath) || is_link($artifactPath)) {
            throw $this->invalid('PLATFORM_ROLE_SOURCE_UNAVAILABLE');
        }
        $artifact = json_decode((string) file_get_contents($artifactPath), true, 64, JSON_THROW_ON_ERROR);
        $sourceHash = hash_file('sha256', $sourcePath);
        if (($artifact['schema_version'] ?? null) !== 1 ||
            ($artifact['source_path'] ?? null) !== self::SHELL_SOURCE ||
            !is_string($sourceHash) || !hash_equals($sourceHash, (string) ($artifact['source_sha256'] ?? '')) ||
            !is_array($artifact['nodes'] ?? null) || !array_is_list($artifact['nodes']) ||
            count($artifact['nodes']) < 1 || count($artifact['nodes']) > 1000) {
            throw $this->invalid('PLATFORM_ROLE_SOURCE_STALE');
        }
        $catalog = GeneratedApiCatalog::load($serverRoot);
        // The native catalog must declare the generated Shell projection as
        // a checked input; a claimed TS source hash inside the JSON alone
        // would not protect against substituting forged node relationships.
        $sourceBound = false;
        foreach ($catalog['inputs'] as $input) {
            if ($input['path'] === 'server/generated/platform-role-source.json') {
                $sourceBound = true;
                break;
            }
        }
        if (!$sourceBound) {
            throw $this->invalid('PLATFORM_ROLE_SOURCE_UNBOUND');
        }
        $apiByRoute = [];
        $hasFile = false;
        $filePaths = [];
        $nodeIds = [];
        foreach ($artifact['nodes'] as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null) ||
                isset($nodeIds[$row['key']]) || !is_array($row['api_endpoints'] ?? null)) {
                throw $this->invalid('PLATFORM_ROLE_SOURCE_INVALID');
            }
            $nodeIds[$row['key']] = true;
            if (str_starts_with($row['key'], 'platform.storage.')) {
                foreach ($row['api_endpoints'] as $endpoint) {
                    $filePaths[$endpoint[1]] = true;
                }
            }
        }
        foreach ($catalog['endpoints'] as $api) {
            if (($api['audience'] ?? '') !== 'platform') {
                continue;
            }
            $route = ($api['method'] ?? '') . ' ' . ($api['path'] ?? '');
            if (isset($apiByRoute[$route])) {
                throw $this->invalid('PLATFORM_ROLE_API_AMBIGUOUS');
            }
            $apiByRoute[$route] = $api;
            if (($api['owner']['key'] ?? null) === 'peanut.file' ||
                isset($filePaths[$api['path'] ?? ''])) {
                $hasFile = true;
            }
        }

        $nodes = [];
        $expectedApis = [];
        $menus = [];
        foreach ($artifact['nodes'] as $definition) {
            $key = $definition['key'];
            // File is an optional Module contribution, never a synthetic fallback.
            if (!$hasFile && ($key === 'platform.storage' || str_starts_with($key, 'platform.storage.'))) {
                continue;
            }
            if (!in_array($definition['type'] ?? null, ['group', 'page', 'action', 'operation'], true) ||
                !array_key_exists('parent_key', $definition) ||
                !array_key_exists('route_path', $definition) ||
                !is_array($definition['api_endpoints']) ||
                !is_string($definition['api_owner'] ?? null)) {
                throw $this->invalid('PLATFORM_ROLE_SOURCE_INVALID');
            }
            $ids = [];
            foreach ($definition['api_endpoints'] as $endpoint) {
                if (!is_array($endpoint) || count($endpoint) !== 2 ||
                    !is_string($endpoint[0]) || !is_string($endpoint[1])) {
                    throw $this->invalid('PLATFORM_ROLE_SOURCE_INVALID');
                }
                $reference = $endpoint[0] . ' ' . $endpoint[1];
                $api = $apiByRoute[$reference] ?? null;
                $owner = ($api['owner']['type'] ?? null) === 'module'
                    ? ($api['owner']['key'] ?? null)
                    : (($api['application'] ?? null) === 'platform' ? 'platform' : null);
                if ($api === null || $owner !== $definition['api_owner'] ||
                    ($api['auth']['mode'] ?? null) !== 'platform_permission' ||
                    ($api['contract_quality'] ?? null) !== 'complete') {
                    throw $this->invalid('PLATFORM_ROLE_API_SOURCE_MISMATCH');
                }
                $id = $api['api_id'];
                if (!is_string($id) || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
                    throw $this->invalid('PLATFORM_ROLE_API_SOURCE_MISMATCH');
                }
                $ids[$id] = $id;
                $expectedApis[$id] = $api;
            }
            $ids = array_values($ids);
            sort($ids, SORT_STRING);
            $nodes[] = ['key' => $key, 'parent_key' => $definition['parent_key'],
                'type' => $definition['type'], 'api_ids' => $ids];
            if (in_array($definition['type'], ['group', 'page'], true)) {
                $menus[$key] = $definition;
            }
        }
        $this->assertCurrentApiRows($expectedApis);
        $this->assertRegisteredMenus($menus);
        return $nodes;
    }

    /** @param array<string,array<string,mixed>> $expected */
    private function assertCurrentApiRows(array $expected): void
    {
        if ($expected === []) {
            throw $this->invalid('PLATFORM_ROLE_API_SOURCE_MISMATCH');
        }
        $rows = Db::name('system_api')->whereIn('id', array_keys($expected))
            ->field('id,method,path,audience,access_mode,status,module_key,default_permission_keys,prerequisite_permission_keys')
            ->select()->toArray();
        if (count($rows) !== count($expected)) {
            throw $this->invalid('PLATFORM_ROLE_API_CATALOG_DRIFT');
        }
        foreach ($rows as $row) {
            $source = $expected[$row['id']] ?? null;
            if ($source === null) {
                throw $this->invalid('PLATFORM_ROLE_API_CATALOG_DRIFT');
            }
            $owner = $source['owner']['type'] === 'module'
                ? $source['owner']['key'] : 'platform';
            if ($row['method'] !== $source['method'] ||
                $row['path'] !== strtolower($source['path']) ||
                $row['audience'] !== 'platform' || $row['access_mode'] !== 'platform_permission' ||
                $row['status'] !== 'active' || $row['module_key'] !== $owner ||
                json_decode((string) $row['default_permission_keys'], true, 32, JSON_THROW_ON_ERROR) !== $source['default_permission_keys'] ||
                json_decode((string) $row['prerequisite_permission_keys'], true, 32, JSON_THROW_ON_ERROR) !== $source['prerequisite_permission_keys']) {
                throw $this->invalid('PLATFORM_ROLE_API_CATALOG_DRIFT');
            }
        }
    }

    /** @param array<string,array<string,mixed>> $definitions */
    private function assertRegisteredMenus(array $definitions): void
    {
        $rows = Db::name('system_menu')->whereIn('key', array_keys($definitions))
            ->field('key,source,module_key,scope,type,parent_key,route_path,status,is_disable,client_keys_json')
            ->select()->toArray();
        if (count($rows) !== count($definitions)) {
            throw $this->invalid('PLATFORM_ROLE_MENU_CATALOG_DRIFT');
        }
        foreach ($rows as $menu) {
            $source = $definitions[$menu['key']] ?? null;
            if ($source === null || $menu['source'] !== 'module' ||
                $menu['module_key'] !== 'platform' || $menu['scope'] !== 'platform' ||
                $menu['type'] !== $source['type'] || $menu['parent_key'] !== $source['parent_key'] ||
                $menu['route_path'] !== $source['route_path'] || $menu['status'] !== 'active' ||
                (bool) $menu['is_disable'] ||
                !in_array('platform-web', json_decode((string) $menu['client_keys_json'], true, 32, JSON_THROW_ON_ERROR), true)) {
                throw $this->invalid('PLATFORM_ROLE_MENU_CATALOG_DRIFT');
            }
        }
    }

    private function invalid(string $code): AdminAccessException
    {
        return AdminAccessException::invalid($code, 'The registered Platform role node/API source is unavailable, invalid or stale.');
    }
}
