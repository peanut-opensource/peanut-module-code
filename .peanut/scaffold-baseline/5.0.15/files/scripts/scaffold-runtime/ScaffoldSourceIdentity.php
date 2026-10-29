<?php

declare(strict_types=1);

namespace app\common\value\scaffold;

use RuntimeException;

require_once __DIR__ . '/ScaffoldConfiguration.php';

/** One identity grammar for source snapshots, manifests and upgrade seals. */
final class ScaffoldSourceIdentity
{
    private static function policy(): array
    {
        static $policy = null;
        if ($policy !== null) { return $policy; }
        $value = ScaffoldConfiguration::load()['source_snapshot'];
        $value['retained_paths'] ??= [];
        foreach (['excluded_names', 'excluded_paths', 'excluded_name_patterns', 'retained_name_patterns', 'retained_paths'] as $key) {
            if (!is_array($value[$key] ?? null) || !array_is_list($value[$key])) { throw new RuntimeException('SCAFFOLD_SOURCE_POLICY_INVALID'); }
            foreach ($value[$key] as $entry) {
                if (!is_string($entry) || $entry === '') { throw new RuntimeException('SCAFFOLD_SOURCE_POLICY_INVALID'); }
            }
        }
        foreach ($value['retained_paths'] as $path) {
            if (str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1
                || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
                throw new RuntimeException('SCAFFOLD_SOURCE_POLICY_INVALID');
            }
        }
        foreach (['excluded_name_patterns', 'retained_name_patterns'] as $key) {
            foreach ($value[$key] as &$pattern) {
                if (str_contains($pattern, '~') || @preg_match('~' . $pattern . '~i', '') === false) {
                    throw new RuntimeException('SCAFFOLD_SOURCE_POLICY_INVALID');
                }
                $pattern = '~' . $pattern . '~i';
            }
            unset($pattern);
        }
        return $policy = $value;
    }

    /** Legacy Git projections are accepted only when no union was declared. */
    public static function from(array $value, bool $generation = false): array
    {
        $commitKey = $generation ? 'commit' : 'source_commit';
        $treeKey = $generation ? 'tree' : 'source_tree';
        $identity = $value['source_identity'] ?? null;
        if (array_key_exists('source_identity', $value) && !is_array($identity)) {
            throw new RuntimeException('SCAFFOLD_SOURCE_IDENTITY_INVALID');
        }
        if ($identity === null) {
            $identity = ['kind' => 'git', 'commit' => $value[$commitKey] ?? null, 'tree' => $value[$treeKey] ?? null];
        }
        $identity = self::validate($identity);
        if ($identity['kind'] === 'local') {
            if (array_intersect(['source_commit', 'source_tree', 'commit', 'tree'], array_keys($value)) !== []) {
                throw new RuntimeException('SCAFFOLD_SOURCE_IDENTITY_MIXED');
            }
        } elseif (($value[$commitKey] ?? null) !== $identity['commit'] || ($value[$treeKey] ?? null) !== $identity['tree']) {
            throw new RuntimeException('SCAFFOLD_SOURCE_IDENTITY_MISMATCH');
        }
        return $identity;
    }

    public static function validate(array $identity): array
    {
        $keys = array_keys($identity);
        sort($keys, SORT_STRING);
        if (($identity['kind'] ?? null) === 'git' && $keys === ['commit', 'kind', 'tree']
            && preg_match('/^[a-f0-9]{40}$/D', (string) ($identity['commit'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{40}$/D', (string) ($identity['tree'] ?? '')) === 1) {
            return ['kind' => 'git', 'commit' => $identity['commit'], 'tree' => $identity['tree']];
        }
        if (($identity['kind'] ?? null) === 'local' && $keys === ['kind', 'snapshot_sha256']
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($identity['snapshot_sha256'] ?? '')) === 1) {
            return ['kind' => 'local', 'snapshot_sha256' => $identity['snapshot_sha256']];
        }
        throw new RuntimeException('SCAFFOLD_SOURCE_IDENTITY_INVALID');
    }

    public static function fields(array $identity, bool $generation = false): array
    {
        $identity = self::validate($identity);
        return ['source_identity' => $identity] + ($identity['kind'] === 'git'
            ? [($generation ? 'commit' : 'source_commit') => $identity['commit'],
                ($generation ? 'tree' : 'source_tree') => $identity['tree']] : []);
    }

    /** @return list<string> Source-relative regular files, with excluded directories pruned before descent. */
    public static function files(string $root, array $additionalExcludedPaths = []): array
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_INVALID'); }
        $retainedPaths = self::policy()['retained_paths'];
        $files = [];
        $configured = $additionalExcludedPaths;
        $configPath = $resolved . '/.local/toolchain/host.json';
        if (is_file($configPath)) {
            $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
            foreach (['release', 'backups'] as $key) {
                $value = $config['paths'][$key] ?? null;
                if (!is_string($value) || $value === '') { continue; }
                $candidate = realpath(str_starts_with($value, '/') ? $value : $resolved . '/' . $value);
                if ($candidate === $resolved) { throw new RuntimeException('SCAFFOLD_SOURCE_OUTPUT_ROOT_CONFLICT'); }
                if (is_string($candidate) && str_starts_with($candidate, $resolved . '/') && is_dir($candidate)) {
                    $configured[] = substr($candidate, strlen($resolved) + 1);
                }
            }
        }
        $visit = static function (string $directory, string $prefix = '', bool $retainedOnly = false) use (&$visit, &$files, $resolved, $configured, $retainedPaths): void {
            $entries = scandir($directory);
            if ($entries === false) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_READ_FAILED'); }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $relative = $prefix . $name;
                if (preg_match('/[\x00-\x1f\x7f\\\\]/', $relative) === 1) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_PATH_INVALID'); }
                $absolute = $resolved . '/' . $relative;
                $directoryEntry = is_dir($absolute) && !is_link($absolute);
                $retained = in_array($relative, $retainedPaths, true) && !$directoryEntry;
                if ($directoryEntry) {
                    foreach ($retainedPaths as $path) {
                        if (str_starts_with($path, $relative . '/')) { $retained = true; break; }
                    }
                }
                if ($retainedOnly && !$retained) { continue; }
                $restricted = $retainedOnly;
                if (self::excluded($relative, $directoryEntry, $configured)) {
                    if (!$retained || self::excluded($relative, $directoryEntry, $configured, false)) { continue; }
                    // Enter an otherwise excluded ancestor only for the declared exact files.
                    $restricted = true;
                }
                if ($directoryEntry && self::nativeScaffoldReleaseRoot($absolute)) { continue; }
                if (is_link($absolute)) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_SYMLINK: ' . $relative); }
                if (is_dir($absolute)) { $visit($absolute, $relative . '/', $restricted); continue; }
                if (!is_file($absolute)) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_TYPE_INVALID: ' . $relative); }
                $files[] = $relative;
            }
        };
        $visit($resolved);
        sort($files, SORT_STRING);
        return $files;
    }

    /** SHA-256(sorted(path NUL octal-mode NUL file-sha256 LF)); bytes match the CLI. */
    public static function local(string $root): array
    {
        $resolved = realpath($root);
        $hash = hash_init('sha256');
        foreach (self::files($root) as $relative) {
            $absolute = $resolved . '/' . $relative;
            $mode = (fileperms($absolute) & 0111) !== 0 ? 0755 : 0644;
            $digest = hash_file('sha256', $absolute);
            if (!is_string($digest)) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_READ_FAILED'); }
            hash_update($hash, $relative . "\0" . decoct($mode) . "\0" . $digest . "\n");
        }
        return ['kind' => 'local', 'snapshot_sha256' => hash_final($hash)];
    }

    /** Portable proof of the canonical source index and the two selection inputs. */
    public static function proof(string $root): array
    {
        $root = (string) realpath($root);
        $rows = [];
        foreach (self::files($root) as $path) {
            $rows[] = ['path' => $path, 'mode' => (fileperms($root . '/' . $path) & 0111) !== 0 ? 0755 : 0644,
                'sha256' => hash_file('sha256', $root . '/' . $path)];
        }
        $proof = ['protocol' => 'peanut.local-source-snapshot.v1', 'source_identity' => self::local($root),
            'files' => $rows, 'inventory_base64' => base64_encode((string) file_get_contents($root . '/scaffold/application-template-inventory.json')),
            'edition_profile_base64' => base64_encode((string) file_get_contents($root . '/scaffold/edition-profiles.json'))];
        self::validateProof($proof, $proof['source_identity']);
        return $proof;
    }

    /** @return array{inventory:array,edition_profiles:array} */
    public static function validateProof(array $proof, array $identity): array
    {
        if (($proof['protocol'] ?? null) !== 'peanut.local-source-snapshot.v1'
            || ($proof['source_identity'] ?? null) !== self::validate($identity) || $identity['kind'] !== 'local'
            || !is_array($proof['files'] ?? null)) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_INVALID'); }
        $rows = []; $previous = null; $hash = hash_init('sha256');
        foreach ($proof['files'] as $file) {
            $path = $file['path'] ?? null;
            if (!is_string($path) || $path === '' || strlen($path) > 4096 || count($rows) >= 100000
                || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1
                || str_starts_with($path, '/') || array_intersect(explode('/', $path), ['', '.', '..']) !== []
                || !in_array($file['mode'] ?? null, [0644, 0755], true)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($file['sha256'] ?? '')) !== 1
                || ($previous !== null && strcmp($previous, $path) >= 0)) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_INVALID'); }
            $rows[$path] = $file; $previous = $path;
            hash_update($hash, $path . "\0" . decoct($file['mode']) . "\0" . $file['sha256'] . "\n");
        }
        if (hash_final($hash) !== $identity['snapshot_sha256']) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_CHANGED'); }
        $decoded = [];
        foreach (['inventory' => 'scaffold/application-template-inventory.json', 'edition_profile' => 'scaffold/edition-profiles.json'] as $key => $path) {
            $encoded = $proof[$key . '_base64'] ?? null;
            $bytes = is_string($encoded) && strlen($encoded) <= 16777216 ? base64_decode($encoded, true) : false;
            if (!is_string($bytes) || base64_encode($bytes) !== $encoded || !isset($rows[$path])
                || hash('sha256', $bytes) !== $rows[$path]['sha256']) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INPUT_CHANGED'); }
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document)) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_INVALID'); }
            $decoded[$key === 'inventory' ? 'inventory' : 'edition_profiles'] = $document;
        }
        $inventory = $decoded['inventory'];
        if (($inventory['protocol'] ?? null) !== 'peanut.create-app-inventory.v2' || !is_array($inventory['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INVENTORY_INVALID');
        }
        $seen = [];
        foreach ($inventory['files'] as $entry) {
            $path = $entry['path'] ?? null;
            if (!is_string($path) || isset($seen[$path])) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INVENTORY_INVALID'); }
            $seen[$path] = true;
            if (($entry['classification'] ?? null) === 'excluded') { continue; }
            $semantic = !str_starts_with($path, 'server/resources/scaffold-application/')
                && in_array($entry['transform'] ?? null, ['changelog', 'release-metadata', 'resources', 'readme', 'docs-page', 'version-contract'], true);
            $expected = $semantic ? hash('sha256', "peanut.create-app-semantic-source.v1\0{$path}\0{$entry['transform']}") : ($rows[$path]['sha256'] ?? null);
            if (!isset($rows[$path]) || ($entry['source_sha256'] ?? null) !== $expected
                || ($entry['mode'] ?? null) !== $rows[$path]['mode']) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INVENTORY_CHANGED'); }
        }
        return $decoded;
    }

    private static function excluded(string $path, bool $directory, array $configured, bool $namePatterns = true): bool
    {
        $name = basename($path);
        $policy = self::policy();
        if (in_array($name, $policy['excluded_names'], true)) { return true; }
        foreach ([...$policy['excluded_paths'], ...$configured] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) { return true; }
        }
        if (!$namePatterns) { return false; }
        foreach ($policy['retained_name_patterns'] as $pattern) {
            if (preg_match($pattern, $name) === 1) { return false; }
        }
        foreach ($policy['excluded_name_patterns'] as $pattern) {
            if (preg_match($pattern, $name) === 1) { return true; }
        }
        return false;
    }

    private static function nativeScaffoldReleaseRoot(string $directory): bool
    {
        try {
            if (is_link($directory) || !is_dir($directory)) { return false; }
            $versions = scandir($directory);
            if (!is_array($versions)) { return false; }
            $versions = array_values(array_diff($versions, ['.', '..']));
            if ($versions === []) { return false; }
            foreach ($versions as $version) {
                if (preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $version) !== 1) { return false; }
                $versionDirectory = $directory . '/' . $version;
                if (is_link($versionDirectory) || !is_dir($versionDirectory)) { return false; }
                $editions = scandir($versionDirectory);
                if (!is_array($editions)) { return false; }
                $editions = array_values(array_diff($editions, ['.', '..']));
                if ($editions === []) { return false; }
                foreach ($editions as $edition) {
                    if (!in_array($edition, ['standalone', 'multi-tenant'], true)) { return false; }
                    $editionDirectory = $versionDirectory . '/' . $edition;
                    if (is_link($editionDirectory) || !is_dir($editionDirectory)
                        || !self::validScaffoldEdition($editionDirectory, $version, $edition)) { return false; }
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function validScaffoldEdition(string $directory, string $version, string $edition): bool
    {
        try {
            $name = 'peanut-scaffold-' . $edition;
            $paths = [
                'package' => $directory . '/' . $name . '.json',
                'archive' => $directory . '/' . $name . '.tgz',
                'manifest' => $directory . '/scaffold-manifest.json',
                'inputs' => $directory . '/scaffold-build-inputs.json',
                'files' => $directory . '/files',
                'snapshot' => $directory . '/source-snapshot.json',
            ];
            foreach (['package' => 4194304, 'archive' => 1073741824, 'manifest' => 33554432,
                'inputs' => 16777216] as $key => $limit) {
                if (!self::ordinaryReleaseFile($paths[$key], $limit)) { return false; }
            }
            if (is_link($paths['files']) || !is_dir($paths['files'])) { return false; }
            $metadata = self::readReleaseJson($paths['package'], 4194304);
            if (($metadata['schema_version'] ?? null) !== 1 || ($metadata['protocol'] ?? null) !== 'peanut.template-package.v1'
                || ($metadata['kind'] ?? null) !== 'scaffold-template' || ($metadata['version'] ?? null) !== $version
                || ($metadata['edition'] ?? null) !== $edition || !in_array($metadata['profile'] ?? null, ['minimal', 'standard', 'full'], true)
                || !in_array($metadata['workflow'] ?? null, ['none', 'runtime', 'full'], true)
                || ($metadata['archive']['name'] ?? null) !== $name . '.tgz'
                || !is_int($metadata['archive']['bytes'] ?? null) || $metadata['archive']['bytes'] <= 0
                || !self::sha256($metadata['archive']['sha256'] ?? null)
                || !self::sha256($metadata['create_manifest_sha256'] ?? null)
                || !self::sha256($metadata['upgrade_tooling_sha256'] ?? null)
                || !self::sha256($metadata['scaffold_manifest_sha256'] ?? null)
                || !self::sourceIdentityValid($metadata['source']['source_identity'] ?? null)
                || !self::sha256($metadata['source']['inventory_sha256'] ?? null)
                || !self::sha256($metadata['source']['edition_profile_sha256'] ?? null)) { return false; }
            $identity = $metadata['source']['source_identity'];
            $hasSnapshot = file_exists($paths['snapshot']) || is_link($paths['snapshot']);
            if ($identity['kind'] === 'local') {
                if (!$hasSnapshot || !self::sha256($metadata['source_snapshot_sha256'] ?? null)
                    || !self::ordinaryReleaseFile($paths['snapshot'], 67108864)
                    || !hash_equals($metadata['source_snapshot_sha256'], (string) hash_file('sha256', $paths['snapshot']))) { return false; }
                $proof = self::readReleaseJson($paths['snapshot'], 67108864);
                $proofInputs = self::validateProof($proof, $identity);
                $inventoryBytes = base64_decode($proof['inventory_base64'] ?? '', true);
                $editionProfileBytes = base64_decode($proof['edition_profile_base64'] ?? '', true);
                if (!is_string($inventoryBytes) || !is_string($editionProfileBytes)
                    || !hash_equals($metadata['source']['inventory_sha256'], hash('sha256', $inventoryBytes))
                    || !hash_equals($metadata['source']['edition_profile_sha256'], hash('sha256', $editionProfileBytes))
                    || ($proofInputs['inventory']['template_version'] ?? null) !== $version
                    || !is_array($proof['payload_files'] ?? null)) { return false; }
            } elseif ($hasSnapshot || (isset($metadata['source_snapshot_sha256']) && $metadata['source_snapshot_sha256'] !== null)) {
                return false;
            }

            $manifestBytes = file_get_contents($paths['manifest']);
            if (!is_string($manifestBytes) || !hash_equals($metadata['scaffold_manifest_sha256'], hash('sha256', $manifestBytes))) { return false; }
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            if (!in_array([$manifest['protocol'] ?? null, $manifest['schema_version'] ?? null], [
                ['peanut.scaffold-release.v3', 3], ['peanut.scaffold-release.v4', 4],
            ], true) || ($manifest['release']['version'] ?? null) !== $version
                || ($manifest['edition']['name'] ?? null) !== $edition
                || !self::sameSourceIdentity($manifest['release']['source_identity'] ?? null, $identity)
                || ($manifest['release']['inventory_sha256'] ?? null) !== $metadata['source']['inventory_sha256']
                || ($manifest['edition']['source_sha256'] ?? null) !== $metadata['source']['edition_profile_sha256']
                || !is_array($manifest['files'] ?? null) || count($manifest['files']) > 100000) { return false; }

            $archiveStat = stat($paths['archive']);
            if (!is_array($archiveStat) || $archiveStat['size'] !== $metadata['archive']['bytes']
                || !hash_equals($metadata['archive']['sha256'], (string) hash_file('sha256', $paths['archive']))) { return false; }
            $inputs = self::readReleaseJson($paths['inputs'], 16777216);
            if (($inputs['protocol'] ?? null) !== 'peanut.scaffold-build-inputs.v1'
                || !self::sha256($inputs['payload_sha256'] ?? null)
                || !self::sameSourceIdentity($inputs['source']['source_identity'] ?? null, $identity)
                || ($inputs['source']['inventory_sha256'] ?? null) !== $metadata['source']['inventory_sha256']
                || ($inputs['source']['edition_profile_sha256'] ?? null) !== $metadata['source']['edition_profile_sha256']
                || !is_array($inputs['artifacts'] ?? null)) { return false; }
            $expectedArtifacts = [$name . '.json', $name . '.tgz', 'scaffold-manifest.json'];
            $artifactNames = array_keys($inputs['artifacts']);
            sort($artifactNames, SORT_STRING);
            sort($expectedArtifacts, SORT_STRING);
            if ($artifactNames !== $expectedArtifacts) { return false; }
            foreach ([$name . '.json' => $paths['package'], $name . '.tgz' => $paths['archive'],
                'scaffold-manifest.json' => $paths['manifest']] as $artifactName => $artifactPath) {
                $artifact = $inputs['artifacts'][$artifactName];
                $stat = stat($artifactPath);
                if (!is_array($stat) || ($artifact['bytes'] ?? null) !== $stat['size']
                    || !self::sha256($artifact['sha256'] ?? null)
                    || !hash_equals($artifact['sha256'], (string) hash_file('sha256', $artifactPath))) { return false; }
            }

            $expectedFiles = [];
            $expectedDirectories = [];
            $totalBytes = 0;
            foreach ($manifest['files'] as $row) {
                $relative = $row['path'] ?? null;
                if (!self::safeManifestPath($relative) || ($row['source'] ?? null) !== 'files/' . $relative
                    || !self::sha256($row['template_sha256'] ?? null) || !in_array($row['mode'] ?? null, [0644, 0755], true)
                    || isset($expectedFiles[$relative])) { return false; }
                $expectedFiles[$relative] = true;
                $parts = explode('/', $relative);
                for ($index = 1; $index < count($parts); $index++) {
                    $expectedDirectories[implode('/', array_slice($parts, 0, $index))] = true;
                }
                $file = $paths['files'] . '/' . $relative;
                $current = $paths['files'];
                foreach ($parts as $index => $part) {
                    $current .= '/' . $part;
                    if (is_link($current)) { return false; }
                    if ($index < count($parts) - 1 ? !is_dir($current) : !is_file($current)) { return false; }
                }
                $stat = stat($file);
                if (!is_array($stat) || ($stat['mode'] & 0777) !== $row['mode']) { return false; }
                $totalBytes += $stat['size'];
                if ($totalBytes > 1073741824 || !hash_equals($row['template_sha256'], (string) hash_file('sha256', $file))) { return false; }
            }
            $actualFiles = [];
            $actualDirectories = [];
            if (!self::scanReleasePayload($paths['files'], '', $actualFiles, $actualDirectories)
                || array_diff_key($actualFiles, $expectedFiles) !== [] || array_diff_key($expectedFiles, $actualFiles) !== []
                || array_diff_key($actualDirectories, $expectedDirectories) !== []
                || array_diff_key($expectedDirectories, $actualDirectories) !== []) { return false; }

            $topLevel = scandir($directory);
            if (!is_array($topLevel)) { return false; }
            $topLevel = array_values(array_diff($topLevel, ['.', '..']));
            $expectedTopLevel = [$name . '.json', $name . '.tgz', 'scaffold-build-inputs.json', 'scaffold-manifest.json', 'files'];
            if ($identity['kind'] === 'local') { $expectedTopLevel[] = 'source-snapshot.json'; }
            sort($topLevel, SORT_STRING);
            sort($expectedTopLevel, SORT_STRING);
            return $topLevel === $expectedTopLevel;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ordinaryReleaseFile(string $path, int $limit): bool
    {
        if (is_link($path) || !is_file($path)) { return false; }
        $size = filesize($path);
        return is_int($size) && $size <= $limit;
    }

    private static function readReleaseJson(string $path, int $limit): array
    {
        if (!self::ordinaryReleaseFile($path, $limit)) { throw new RuntimeException('invalid release file'); }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new RuntimeException('invalid release json'); }
        return $decoded;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function sourceIdentityValid(mixed $identity): bool
    {
        if (!is_array($identity)) { return false; }
        try {
            self::validate($identity);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function sameSourceIdentity(mixed $left, mixed $right): bool
    {
        return self::sourceIdentityValid($left) && self::sourceIdentityValid($right)
            && self::validate($left) === self::validate($right);
    }

    private static function safeManifestPath(mixed $path): bool
    {
        if (!is_string($path) || $path === '' || str_starts_with($path, '/')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1) { return false; }
        return !in_array('', explode('/', $path), true) && !in_array('.', explode('/', $path), true)
            && !in_array('..', explode('/', $path), true);
    }

    private static function scanReleasePayload(string $directory, string $prefix, array &$files, array &$directories): bool
    {
        $entries = scandir($directory);
        if (!is_array($entries)) { return false; }
        foreach (array_diff($entries, ['.', '..']) as $name) {
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            $absolute = $directory . '/' . $name;
            if (is_link($absolute)) { return false; }
            if (is_dir($absolute)) {
                $directories[$relative] = true;
                if (!self::scanReleasePayload($absolute, $relative, $files, $directories)) { return false; }
            } elseif (is_file($absolute)) {
                $files[$relative] = true;
            } else { return false; }
        }
        return true;
    }
}
