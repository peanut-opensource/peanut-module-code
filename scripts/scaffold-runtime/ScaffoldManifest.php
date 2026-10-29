<?php

declare(strict_types=1);

namespace app\common\value\scaffold;

require_once __DIR__ . '/ScaffoldSourceIdentity.php';

use app\common\validation\scaffold\ScaffoldPathGuard;
use RuntimeException;

final class ScaffoldManifest
{
    private const POLICIES = ['managed', 'generated'];
    private const OWNERS = ['host', 'backend', 'frontend'];
    private const VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    private const COMPOSER_CONTENT_HASH_KEYS = [
        'name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide',
        'minimum-stability', 'prefer-stable', 'repositories', 'extra',
    ];

    /** @param array<string,mixed> $data */
    private function __construct(
        public readonly string $path,
        public readonly string $directory,
        public readonly array $data,
    ) {}

    public static function load(string $path, bool $allowRecordedBaseline = false): self
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException("SCAFFOLD_MANIFEST_NOT_FOUND: {$path}");
        }
        $raw = file_get_contents($resolved);
        try {
            $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_MANIFEST_INVALID_JSON: ' . $exception->getMessage(), 0, $exception);
        }
        $protocol = $data['protocol'] ?? null;
        $supported = is_array($data) && (
            (($data['schema_version'] ?? null) === 2 && $protocol === 'peanut.scaffold-release.v2')
            || (($data['schema_version'] ?? null) === 3 && $protocol === 'peanut.scaffold-release.v3')
            || (($data['schema_version'] ?? null) === 4 && $protocol === 'peanut.scaffold-release.v4')
            || ($allowRecordedBaseline && ($data['schema_version'] ?? null) === 1 && $protocol === 'peanut.scaffold-recorded-baseline.v1')
        );
        if (!$supported) {
            throw new RuntimeException('SCAFFOLD_MANIFEST_SCHEMA_UNSUPPORTED');
        }
        $release = $data['release'] ?? null;
        $identityValid = is_array($release) && self::validSourceIdentity($release)
            && ($protocol !== 'peanut.scaffold-release.v4' || isset($release['source_identity']))
            && (($release['source_identity']['kind'] ?? null) !== 'local'
                || in_array($protocol, ['peanut.scaffold-release.v4', 'peanut.scaffold-recorded-baseline.v1'], true));
        if (!is_array($release) || preg_match(self::VERSION_PATTERN, (string) ($release['version'] ?? '')) !== 1
            || !$identityValid
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($release['inventory_sha256'] ?? '')) !== 1
            || !self::nonEmptyString($release['inventory_template_version'] ?? null)
            || !is_array($release['tokens'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_MANIFEST_RELEASE_INVALID');
        }
        if ($protocol === 'peanut.scaffold-recorded-baseline.v1') {
            $record = $data['recorded_from'] ?? null;
            if (!is_array($record) || preg_match('/^[a-f0-9]{64}$/D', (string) ($record['application_manifest_sha256'] ?? '')) !== 1
                || !is_string($record['application_root'] ?? null) || $release['tokens'] !== []) {
                throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_INVALID');
            }
            $applicationRoot = ScaffoldPathGuard::projectRoot($record['application_root']);
            $expected = $applicationRoot . '/.peanut/scaffold-baseline/' . $release['version']
                . '/recorded-' . $record['application_manifest_sha256'] . '.json';
            if ($resolved !== $expected || is_link($path)) { throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_PATH_INVALID'); }
            foreach ($data['files'] ?? [] as $file) {
                if (($file['source'] ?? null) !== 'files/' . ($file['path'] ?? '')) { throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_PATH_INVALID'); }
            }
            if (preg_match(self::VERSION_PATTERN, (string) ($data['application']['version'] ?? '')) !== 1) {
                throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_VERSION_INVALID');
            }
        } elseif ($protocol === 'peanut.scaffold-release.v3' || $protocol === 'peanut.scaffold-release.v4') {
            $application = $data['application'] ?? null;
            $v4Generic = $protocol === 'peanut.scaffold-release.v4' && $application === null
                && array_keys($release['tokens']) === ['product_name', 'slug', 'package_identity'];
            $v4Application = $protocol === 'peanut.scaffold-release.v4' && is_array($application)
                && array_keys($application) === ['version']
                && preg_match(self::VERSION_PATTERN, (string) $application['version']) === 1
                && array_keys($release['tokens']) === ['product_name', 'slug', 'package_identity', 'application_version'];
            $v3Application = $protocol === 'peanut.scaffold-release.v3' && is_array($application)
                && array_keys($application) === ['version']
                && preg_match(self::VERSION_PATTERN, (string) $application['version']) === 1
                && array_keys($release['tokens']) === ['product_name', 'slug', 'package_identity', 'application_version'];
            if (!$v4Generic && !$v4Application && !$v3Application) {
                throw new RuntimeException('SCAFFOLD_MANIFEST_APPLICATION_INVALID');
            }
        } elseif (array_keys($release['tokens']) !== ['product_name', 'slug', 'package_identity']) {
            throw new RuntimeException('SCAFFOLD_MANIFEST_RELEASE_INVALID');
        }
        foreach ($release['tokens'] as $token) {
            if (!self::nonEmptyString($token)) {
                throw new RuntimeException('SCAFFOLD_MANIFEST_RELEASE_INVALID');
            }
        }
        $files = $data['files'] ?? null;
        if (!is_array($files)) {
            throw new RuntimeException('SCAFFOLD_MANIFEST_FILES_INVALID');
        }
        $seen = [];
        foreach ($files as $index => $file) {
            if (!is_array($file)) {
                throw new RuntimeException("SCAFFOLD_MANIFEST_FILE_INVALID: {$index}");
            }
            self::validateFile($file, (string) $index);
            if (isset($seen[$file['path']])) {
                throw new RuntimeException('SCAFFOLD_MANIFEST_PATH_DUPLICATE: ' . $file['path']);
            }
            $seen[$file['path']] = $file;
        }
        $renames = $data['renames'] ?? [];
        if (!is_array($renames)) {
            throw new RuntimeException('SCAFFOLD_MANIFEST_RENAMES_INVALID');
        }
        $renameFrom = [];
        $renameTo = [];
        foreach ($renames as $index => $rename) {
            if (!is_array($rename)) {
                throw new RuntimeException("SCAFFOLD_MANIFEST_RENAME_INVALID: {$index}");
            }
            $from = self::path((string) ($rename['from'] ?? ''));
            $to = self::path((string) ($rename['to'] ?? ''));
            $owner = (string) ($rename['owner'] ?? '');
            self::owner($owner);
            if ($from === $to
                || isset($renameFrom[$from]) || isset($renameTo[$to])
                || isset($renameFrom[$to]) || isset($renameTo[$from])) {
                throw new RuntimeException("SCAFFOLD_MANIFEST_RENAME_INVALID: {$index}");
            }
            if (isset($seen[$from]) || !isset($seen[$to])) {
                throw new RuntimeException("SCAFFOLD_MANIFEST_RENAME_TARGET_INVALID: {$from} -> {$to}");
            }
            if (($seen[$to]['owner'] ?? null) !== $owner) {
                throw new RuntimeException("SCAFFOLD_MANIFEST_RENAME_OWNER_MISMATCH: {$from} -> {$to}");
            }
            $renameFrom[$from] = true;
            $renameTo[$to] = true;
        }

        return new self($resolved, dirname($resolved), $data);
    }

    public function version(): string
    {
        return (string) $this->data['release']['version'];
    }

    /** Shared new-path decision; matching bytes alone do not grant ownership. */
    public static function additionDecision(bool $present, bool $matchesOwnedTarget): array
    {
        if (!$present) {
            return ['action' => 'create', 'reason' => 'new_managed_file', 'conflict' => false];
        }
        return $matchesOwnedTarget
            ? ['action' => 'preserve', 'reason' => 'existing_matches_target', 'conflict' => false]
            : ['action' => 'conflict', 'reason' => 'new_path_already_exists', 'conflict' => true];
    }

    public function supportsApplicationVersion(): bool
    {
        return (in_array($this->data['protocol'], ['peanut.scaffold-release.v3', 'peanut.scaffold-release.v4'], true)
            && isset($this->data['application']['version'])) || $this->isRecordedBaseline();
    }

    /** @return array{kind:'git',commit:string,tree:string}|array{kind:'local',snapshot_sha256:string} */
    public function sourceIdentity(): array
    {
        return ScaffoldSourceIdentity::from($this->release());
    }

    private static function validSourceIdentity(array $release): bool
    {
        try { ScaffoldSourceIdentity::from($release); return true; }
        catch (RuntimeException) { return false; }
    }

    public function isRecordedBaseline(): bool
    {
        return $this->data['protocol'] === 'peanut.scaffold-recorded-baseline.v1';
    }

    /** Rebind only while planning against the old APP; apply/recovery may load
     * the immutable old input after the live APP manifest has legitimately advanced.
     */
    public function assertRecordedApplication(string $root, array $application, string $applicationDigest): void
    {
        if (!$this->isRecordedBaseline()) { return; }
        $record = $this->data['recorded_from'];
        if ($record['application_root'] !== $root || $record['application_manifest_sha256'] !== preg_replace('/^sha256:/', '', $applicationDigest)) {
            throw new RuntimeException('SCAFFOLD_RECORDED_APPLICATION_CHANGED');
        }
        if ($this->sourceIdentity() !== ScaffoldSourceIdentity::from($application['template'])) { throw new RuntimeException('SCAFFOLD_RECORDED_APPLICATION_CHANGED'); }
        foreach (['version', 'inventory_sha256'] as $key) {
            if (($this->release()[$key] ?? null) !== ($application['template'][$key] ?? null)) { throw new RuntimeException('SCAFFOLD_RECORDED_APPLICATION_CHANGED'); }
        }
        $proof = isset($record['mode_manifest_path']) ? self::load($record['mode_manifest_path']) : null;
        if ($proof !== null && $proof->digest() !== $record['mode_manifest_sha256']) { throw new RuntimeException('SCAFFOLD_BASELINE_MODE_PROOF_CHANGED'); }
        if ($proof !== null) {
            if ($proof->sourceIdentity() !== ScaffoldSourceIdentity::from($application['template'])) { throw new RuntimeException('SCAFFOLD_BASELINE_MODE_PROOF_SOURCE_MISMATCH'); }
            foreach (['version', 'inventory_sha256'] as $key) {
                if (($proof->release()[$key] ?? null) !== ($application['template'][$key] ?? null)) { throw new RuntimeException('SCAFFOLD_BASELINE_MODE_PROOF_SOURCE_MISMATCH'); }
            }
        }
        $files = $this->files();
        $seen = [];
        foreach ($application['files'] as $entry) {
            if (!in_array($entry['classification'] ?? null, ['managed', 'generated-managed'], true)) { continue; }
            $path = self::path((string) ($entry['path'] ?? ''));
            $file = $files[$path] ?? null;
            $expected = '.peanut/scaffold-baseline/' . $this->version() . '/files/' . $path;
            $mode = $entry['baseline_mode'] ?? ($proof?->files()[$path]['mode'] ?? null);
            if (isset($seen[$path]) || !is_array($file) || ($entry['baseline_path'] ?? null) !== $expected
                || $file['classification'] !== $entry['classification'] || $file['template_sha256'] !== ($entry['baseline_sha256'] ?? null)
                || $mode !== $file['mode']) { throw new RuntimeException('SCAFFOLD_RECORDED_APPLICATION_CHANGED'); }
            $seen[$path] = true;
            $baseline = ScaffoldPathGuard::existingFileWithin($root, ScaffoldPathGuard::projectPath($root, $expected), 'SCAFFOLD_RECORDED_BASELINE_MISSING');
            $stat = lstat($baseline);
            if ($stat === false || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0644
                || hash_file('sha256', $baseline) !== $file['template_sha256']) { throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_DRIFT'); }
        }
        if (count($seen) !== count($files)) { throw new RuntimeException('SCAFFOLD_RECORDED_APPLICATION_CHANGED'); }
    }

    /** Export the APP's recorded bytes, never a reconstructed official release.
     * Old APPs without baseline_mode require an exact original release as mode evidence.
     */
    public static function writeRecordedBaseline(string $projectRoot, ?string $modeManifestPath = null): string
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $applicationPath = ScaffoldPathGuard::existingFileWithin($root, $root . '/.peanut/application-manifest.json', 'SCAFFOLD_APPLICATION_MANIFEST_INVALID');
        $raw = file_get_contents($applicationPath);
        $application = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($application) || ($application['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
            || !is_array($application['files'] ?? null) || !is_array($application['template'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_INVALID');
        }
        $template = $application['template'];
        $version = (string) ($template['version'] ?? '');
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) { throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_VERSION_INVALID'); }
        $modeProof = $modeManifestPath === null ? null : self::load($modeManifestPath);
        if ($modeProof !== null) {
            if ($modeProof->isRecordedBaseline()) { throw new RuntimeException('SCAFFOLD_BASELINE_MODE_PROOF_INVALID'); }
            if ($modeProof->sourceIdentity() !== ScaffoldSourceIdentity::from($template)) { throw new RuntimeException('SCAFFOLD_BASELINE_MODE_PROOF_SOURCE_MISMATCH'); }
            foreach (['version', 'inventory_sha256'] as $key) {
                if (($modeProof->release()[$key] ?? null) !== ($template[$key] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_BASELINE_MODE_PROOF_SOURCE_MISMATCH');
                }
            }
        }
        $baselineRoot = '.peanut/scaffold-baseline/' . $version;
        $files = [];
        $seen = [];
        foreach ($application['files'] as $entry) {
            if (!is_array($entry) || !in_array($entry['classification'] ?? null, ['managed', 'generated-managed'], true)) { continue; }
            $path = self::path((string) ($entry['path'] ?? ''));
            $baseline = $baselineRoot . '/files/' . $path;
            if (isset($seen[$path]) || ($entry['baseline_path'] ?? null) !== $baseline) { throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_PATH_INVALID'); }
            $seen[$path] = true;
            $file = ScaffoldPathGuard::existingFileWithin($root, ScaffoldPathGuard::projectPath($root, $baseline), 'SCAFFOLD_RECORDED_BASELINE_MISSING');
            $stat = lstat($file);
            $digest = hash_file('sha256', $file);
            if ($stat === false || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0644
                || !is_string($entry['baseline_sha256'] ?? null) || $digest !== $entry['baseline_sha256']) {
                throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_DRIFT: ' . $path);
            }
            $mode = $entry['baseline_mode'] ?? ($modeProof?->files()[$path]['mode'] ?? null);
            if (!in_array($mode, [0644, 0755], true)) { throw new RuntimeException('SCAFFOLD_BASELINE_MODE_EVIDENCE_REQUIRED: ' . $path); }
            $owner = str_starts_with($path, 'server/') ? 'backend'
                : (preg_match('#^(?:web|platform|pc|uniapp|docs-site)/#D', $path) === 1 ? 'frontend' : 'host');
            $files[] = ['path' => $path, 'source' => 'files/' . $path, 'template_sha256' => $digest,
                'classification' => $entry['classification'], 'transform' => 'tokens', 'mode' => $mode,
                'policy' => $entry['classification'] === 'generated-managed' ? 'generated' : 'managed', 'owner' => $owner];
        }
        usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        if ($files === []) { throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_EMPTY'); }
        $document = ['schema_version' => 1, 'protocol' => 'peanut.scaffold-recorded-baseline.v1',
            'recorded_from' => ['application_root' => $root, 'application_manifest_sha256' => hash('sha256', $raw),
                'mode_manifest_path' => $modeProof?->path, 'mode_manifest_sha256' => $modeProof?->digest(),
                'claim' => 'recorded APP baseline bytes, not official release authentication'],
            'application' => ['version' => $application['application']['version']], 'edition' => $application['edition'],
            'release' => $template + ['inventory_template_version' => $version,
                'managed_tree_sha256' => hash('sha256', implode("\n", array_map(static fn(array $file): string => $file['path'] . "\0" . $file['template_sha256'], $files))), 'tokens' => []],
            'files' => $files, 'renames' => []];
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $path = ScaffoldPathGuard::projectPath($root, $baselineRoot . '/recorded-' . hash('sha256', $raw) . '.json');
        if (file_exists($path) || is_link($path)) {
            $existing = ScaffoldPathGuard::existingFileWithin($root, $path, 'SCAFFOLD_RECORDED_BASELINE_INVALID');
            if (file_get_contents($existing) !== $json) { throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_MANIFEST_DRIFT'); }
        } else {
            $temporary = $path . '.stage-' . bin2hex(random_bytes(8));
            $handle = fopen($temporary, 'x+b');
            if ($handle === false) { throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_WRITE_FAILED'); }
            try {
                if (!chmod($temporary, 0600) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)
                    || (function_exists('fsync') && !fsync($handle))) {
                    throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_WRITE_FAILED');
                }
                if (!link($temporary, $path) && (!is_file($path) || is_link($path) || file_get_contents($path) !== $json)) {
                    throw new RuntimeException('SCAFFOLD_RECORDED_BASELINE_MANIFEST_DRIFT');
                }
            } finally { fclose($handle); unlink($temporary); }
        }
        self::load($path, true);
        return $path;
    }

    public function defaultApplicationVersion(): ?string
    {
        return $this->supportsApplicationVersion() ? (string) $this->data['application']['version'] : null;
    }

    /** @return array<string,array<string,mixed>> */
    public function files(): array
    {
        $files = [];
        foreach ($this->data['files'] as $file) {
            $files[(string) $file['path']] = $file;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<int,array<string,mixed>> */
    public function renames(): array
    {
        return array_values($this->data['renames'] ?? []);
    }

    /** Native profile projection retains the authenticated archive identity and artifact paths. */
    public function withoutPaths(array $excluded): self
    {
        $excluded = array_fill_keys($excluded, true);
        $data = $this->data;
        $data['files'] = array_values(array_filter($data['files'],
            static fn(array $file): bool => !isset($excluded[$file['path']])));
        $data['renames'] = array_values(array_filter($data['renames'] ?? [], static function (array $rename) use ($excluded): bool {
            if (isset($excluded[$rename['from']]) !== isset($excluded[$rename['to']])) {
                throw new RuntimeException('SCAFFOLD_PROFILE_RENAME_CROSSES_SELECTION');
            }
            return !isset($excluded[$rename['to']]);
        }));
        return new self($this->path, $this->directory, $data);
    }

    public function digest(): string
    {
        return 'sha256:' . hash_file('sha256', $this->path);
    }

    /** @return array<string,mixed> */
    public function release(): array
    {
        return $this->data['release'];
    }

    public function artifactPath(array $file): string
    {
        $source = (string) ($file['source'] ?? ('files/' . $file['path']));
        self::path($source);
        $candidate = $this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source);
        return ScaffoldPathGuard::existingFileWithin($this->directory, $candidate, 'SCAFFOLD_ARTIFACT_PATH_INVALID');
    }

    /**
     * Forward-render the selected release bytes without creating an application.
     * @param array<string,string> $tokens @param array<string,string> $values
     */
    public static function renderArtifactContent(
        string $content,
        string $path,
        string $transform,
        array $tokens,
        array $values,
        ?string $composerContent = null,
    ): string {
        if (str_starts_with($path, 'server/database/migrations/')
            || preg_match('#^packages/core-(?:php|web)/[^/]+\.(?:zip|tgz)$#D', $path) === 1) {
            return $content;
        }
        $replace = static function (string $input) use ($tokens, $values): string {
            foreach (array_keys($tokens) as $key) {
                $input = str_replace($tokens[$key], $values[$key], $input);
            }
            return $input;
        };
        $rendered = $replace($content);
        if ($transform !== 'composer-lock') {
            return $rendered;
        }
        if ($composerContent === null) {
            throw new RuntimeException('SCAFFOLD_COMPOSER_COMPANION_MISSING');
        }
        return self::renderComposerLock($rendered, $replace($composerContent));
    }

    /**
     * Reproduce Composer 2.10.2's Locker::getContentHash() and update only the
     * top-level lock content hash. Dependency resolution remains immutable.
     */
    public static function renderComposerLock(string $lockContent, string $composerContent): string
    {
        try {
            $lock = json_decode($lockContent, true, 512, JSON_THROW_ON_ERROR);
            $composer = json_decode($composerContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_COMPOSER_LOCK_JSON_INVALID: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($lock) || array_is_list($lock)
            || !is_string($lock['content-hash'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $lock['content-hash']) !== 1
            || !is_array($composer) || array_is_list($composer)) {
            throw new RuntimeException('SCAFFOLD_COMPOSER_LOCK_INVALID');
        }

        $relevant = [];
        foreach (array_intersect(self::COMPOSER_CONTENT_HASH_KEYS, array_keys($composer)) as $key) {
            $relevant[$key] = $composer[$key];
        }
        if (isset($composer['config']['platform'])) {
            $relevant['config']['platform'] = $composer['config']['platform'];
        }
        ksort($relevant, SORT_STRING);
        $encoded = json_encode($relevant, 0);
        if (!is_string($encoded)) {
            throw new RuntimeException('SCAFFOLD_COMPOSER_LOCK_INVALID');
        }
        $hash = hash('md5', $encoded);
        $range = self::topLevelJsonStringValueRange($lockContent, 'content-hash');
        if ($range === null) {
            throw new RuntimeException('SCAFFOLD_COMPOSER_LOCK_INVALID');
        }
        [$start, $end] = $range;
        $rendered = substr($lockContent, 0, $start) . '"' . $hash . '"' . substr($lockContent, $end);
        try {
            $renderedLock = json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_COMPOSER_LOCK_JSON_INVALID: ' . $exception->getMessage(), 0, $exception);
        }
        $expected = $lock;
        $expected['content-hash'] = $hash;
        if ($renderedLock !== $expected) {
            throw new RuntimeException('SCAFFOLD_COMPOSER_LOCK_INVALID');
        }
        return $rendered;
    }

    /** @return array{int,int}|null byte offsets including the JSON string quotes. */
    private static function topLevelJsonStringValueRange(string $json, string $key): ?array
    {
        $length = strlen($json);
        $depth = 0;
        $matches = [];
        for ($offset = 0; $offset < $length;) {
            $character = $json[$offset];
            if ($character === '"') {
                $keyStart = $offset;
                $keyEnd = self::jsonStringEnd($json, $offset);
                if ($keyEnd === null) {
                    return null;
                }
                $rawKey = substr($json, $keyStart, $keyEnd - $keyStart);
                try {
                    $decodedKey = json_decode($rawKey, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return null;
                }
                $cursor = $keyEnd;
                while ($cursor < $length && ctype_space($json[$cursor])) {
                    $cursor++;
                }
                if ($depth === 1 && $decodedKey === $key && ($json[$cursor] ?? null) === ':') {
                    $cursor++;
                    while ($cursor < $length && ctype_space($json[$cursor])) {
                        $cursor++;
                    }
                    if (($json[$cursor] ?? null) !== '"') {
                        return null;
                    }
                    $valueEnd = self::jsonStringEnd($json, $cursor);
                    if ($valueEnd === null) {
                        return null;
                    }
                    $matches[] = [$cursor, $valueEnd];
                    $offset = $valueEnd;
                    continue;
                }
                $offset = $keyEnd;
                continue;
            }
            if ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === '}' || $character === ']') {
                $depth--;
            }
            $offset++;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function jsonStringEnd(string $json, int $start): ?int
    {
        $length = strlen($json);
        $escaped = false;
        for ($offset = $start + 1; $offset < $length; $offset++) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($json[$offset] === '\\') {
                $escaped = true;
                continue;
            }
            if ($json[$offset] === '"') {
                return $offset + 1;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $file */
    private static function validateFile(array $file, string $index): void
    {
        $path = (string) ($file['path'] ?? '');
        self::path($path);
        $policy = (string) ($file['policy'] ?? '');
        if (!in_array($policy, self::POLICIES, true)) {
            throw new RuntimeException("SCAFFOLD_MANIFEST_POLICY_INVALID: {$path}");
        }
        self::owner((string) ($file['owner'] ?? ''));
        $classification = (string) ($file['classification'] ?? '');
        $transform = $file['transform'] ?? null;
        if (!in_array($classification, ['managed', 'generated-managed'], true)
            || ($classification === 'generated-managed') !== ($policy === 'generated')
            || !($transform === 'tokens' || ($path === 'server/composer.lock' && $transform === 'composer-lock'))
            || !in_array($file['mode'] ?? null, [0644, 0755], true)) {
            throw new RuntimeException("SCAFFOLD_MANIFEST_FILE_INVALID: {$path}");
        }
        $digest = $file['template_sha256'] ?? null;
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new RuntimeException("SCAFFOLD_MANIFEST_DIGEST_INVALID: {$path}");
        }
    }

    public static function path(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new RuntimeException("SCAFFOLD_PATH_OUTSIDE_PROJECT: {$path}");
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException("SCAFFOLD_PATH_OUTSIDE_PROJECT: {$path}");
            }
        }
        return $path;
    }

    private static function owner(string $owner): void
    {
        if (!in_array($owner, self::OWNERS, true)) {
            throw new RuntimeException("SCAFFOLD_MANIFEST_OWNER_INVALID: {$owner}");
        }
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
