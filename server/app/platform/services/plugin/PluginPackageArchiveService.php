<?php

declare(strict_types=1);

namespace app\platform\services\plugin;

use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\platform\exception\plugin\PluginArtifactToolException;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\infrastructure\plugin\DeterministicTarArchive;
use app\platform\infrastructure\plugin\PluginArtifactWriter;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\VerifiedPluginPackage;
use app\common\module\PackageCheck;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PeanutAdmin\Kernel\Module\ModuleKey;

/** Builds and verifies self-contained single-Module and multi-Module tar packages. */
final class PluginPackageArchiveService
{
    private DeterministicTarArchive $tar;

    public function __construct(private readonly string $serverRoot)
    {
        $this->tar = new DeterministicTarArchive();
    }

    /**
     * @param array{key_id:string,secret_key:string}|null $signer
     * @return array{path:string,package_key:string,version:string,sha256:string,modules:list<string>}
     */
    public function packModule(string $moduleKey, string $outputPath, ?array $signer = null): array
    {
        $inspection = (new PackageCheck($this->projectRoot()))->inspect($moduleKey);
        return $this->pack($moduleKey, $inspection['version'], [$moduleKey], $outputPath, $signer);
    }

    /**
     * Builds the same canonical payload used by module:pack without writing an archive.
     * The caller selects only a registered Module key; no filesystem path is accepted.
     *
     * @return array{
     *   module_key:string,version:string,file_count:int,total_bytes:int,inventory_sha256:string,
     *   files:list<array{path:string,size:int,sha256:string}>
     * }
     */
    public function previewModule(string $moduleKey): array
    {
        $inspection = (new PackageCheck($this->projectRoot()))->inspect($moduleKey);
        $built = $this->packageEntries($moduleKey, $inspection['version'], [$moduleKey]);
        $files = [];
        $totalBytes = 0;
        foreach ($built['entries'] as $path => $entry) {
            if (isset($entry['source'])) {
                $size = filesize((string) $entry['source']);
                $digest = hash_file('sha256', (string) $entry['source']);
            } else {
                $contents = (string) $entry['contents'];
                $size = strlen($contents);
                $digest = hash('sha256', $contents);
            }
            if (!is_int($size) || !is_string($digest)) {
                throw new PluginPackageException('MODULE_PACKAGE_SOURCE_INVALID', 'Package preview source metadata failed.');
            }
            $totalBytes += $size;
            $files[] = [
                'path' => $path,
                'size' => $size,
                'sha256' => $digest,
            ];
        }
        return [
            'module_key' => $moduleKey,
            'version' => $inspection['version'],
            'file_count' => count($files),
            'total_bytes' => $totalBytes,
            'inventory_sha256' => hash('sha256', $built['inventory']),
            'files' => $files,
        ];
    }

    /**
     * @param list<string> $moduleKeys
     * @param array{key_id:string,secret_key:string}|null $signer
     * @return array{path:string,package_key:string,version:string,sha256:string,modules:list<string>}
     */
    public function packBundle(
        string $packageKey,
        string $version,
        array $moduleKeys,
        string $outputPath,
        ?array $signer = null,
    ): array {
        if (count($moduleKeys) < 2) {
            throw new PluginPackageException('MODULE_PACKAGE_BUNDLE_INVALID', 'A bundle must contain at least two Modules.');
        }
        return $this->pack($packageKey, $version, $moduleKeys, $outputPath, $signer);
    }

    /**
     * @param array<string,string> $trustedPublicKeys key_id => raw Ed25519 public key
     * @param array<string,string> $availableVersions installed/locked external Module versions
     */
    public function verify(
        string $archivePath,
        ?string $expectedSha256,
        array $trustedPublicKeys,
        ?string $signatureKeyId,
        array $availableVersions = [],
    ): VerifiedPluginPackage {
        $archiveDigest = hash_file('sha256', $archivePath);
        if (!is_string($archiveDigest)) {
            throw new PluginPackageException('MODULE_PACKAGE_UNREADABLE', 'Package archive is unreadable.');
        }
        if ($expectedSha256 !== null) {
            $expectedSha256 = strtolower(trim($expectedSha256));
            if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1
                || !hash_equals($expectedSha256, $archiveDigest)) {
                throw new PluginPackageException('MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH', 'Package archive digest differs.');
            }
        }

        $entries = $this->tar->scan($archivePath);
        $inventoryEntry = $entries['META-INF/files.sha256'] ?? null;
        if (!is_array($inventoryEntry)) {
            throw new PluginPackageException('MODULE_PACKAGE_INVENTORY_INVALID', 'Package inventory is missing.');
        }
        $inventoryBytes = $this->tar->read($archivePath, $inventoryEntry);
        $verifiedSignatureKey = $this->verifySignature(
            $archivePath,
            $entries,
            $inventoryBytes,
            $trustedPublicKeys,
            $signatureKeyId,
            $expectedSha256 === null,
        );
        if ($expectedSha256 === null && $verifiedSignatureKey === null) {
            throw new PluginPackageException('MODULE_PACKAGE_SOURCE_UNTRUSTED', 'Package has no trusted source proof.');
        }

        $stageRoot = $this->projectRoot() . '/.local/module-staging/' . bin2hex(random_bytes(16));
        try {
            $this->tar->extract($archivePath, $entries, $stageRoot);
            $inventory = $this->verifyInventory($stageRoot, $entries, $inventoryBytes);
            [$packageKey, $manifestRelative, $manifest] = $this->pluginManifest($stageRoot, $inventory);
            $this->assertPluginSchema($manifest);
            if (($manifest['key'] ?? null) !== $packageKey) {
                throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Plugin key differs from its package path.');
            }
            $packageVersion = is_string($manifest['version'] ?? null) ? $manifest['version'] : '';
            if ($verifiedSignatureKey !== null) {
                $this->assertSignatureIdentity(
                    $archivePath,
                    $entries['META-INF/signatures/' . $verifiedSignatureKey . '.json'],
                    $packageKey,
                    $packageVersion,
                );
            }
            $modules = [];
            $ownedTables = [];
            $preflight = new PackageCheck($stageRoot);
            foreach ($manifest['modules'] ?? [] as $module) {
                if (!is_array($module) || !is_string($module['key'] ?? null) || !is_string($module['root'] ?? null)) {
                    throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Plugin Module entry is invalid.');
                }
                $key = $module['key'];
                if (isset($modules[$key])) {
                    throw new PluginPackageException('MODULE_PACKAGE_DUPLICATE_MODULE', 'Package contains a duplicate Module key.');
                }
                $inspection = $preflight->inspect($key);
                if ($module['root'] !== $inspection['backend_relative']) {
                    throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Plugin Module root is not key-derived.');
                }
                foreach ($inspection['owned_tables'] as $table) {
                    if (isset($ownedTables[$table])) {
                        throw new PluginPackageException('MODULE_PACKAGE_TABLE_OWNERSHIP_CONFLICT', 'Owned table has multiple Module owners.');
                    }
                    $ownedTables[$table] = $key;
                }
                $modules[$key] = $inspection;
            }
            if ($modules === []) {
                throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Package contains no Modules.');
            }
            if (count($modules) === 1 && array_key_first($modules) !== $packageKey) {
                throw new PluginPackageException('MODULE_PACKAGE_SINGLE_IDENTITY_INVALID', 'Single-Module package key must equal its Module key.');
            }
            if (count($modules) > 1 && isset($modules[$packageKey])) {
                throw new PluginPackageException('MODULE_PACKAGE_BUNDLE_IDENTITY_INVALID', 'Bundle key must not impersonate a member Module.');
            }
            $dependencyModules = [];
            foreach ($modules as $key => $inspection) {
                $dependencyModules[$key] = [
                    'version' => $inspection['version'],
                    'dependencies' => $inspection['dependencies'],
                ];
            }
            $dependencyOrder = $preflight->dependencyOrder($dependencyModules, $availableVersions);

            $temporaryLock = $stageRoot . '/plugins.lock';
            $entry = $manifest + [
                'manifest' => $manifestRelative,
                'manifest_sha256' => hash_file('sha256', $stageRoot . '/' . $manifestRelative),
            ];
            $lockEntry = [
                'key' => $entry['key'],
                'version' => $entry['version'],
                'source' => $entry['source'],
                'trust' => $entry['trust'],
                'composer' => $entry['composer'],
                'npm' => $entry['npm'],
                'frontend' => $entry['frontend'],
                'modules' => $entry['modules'],
                'manifest' => $entry['manifest'],
                'manifest_sha256' => $entry['manifest_sha256'],
            ];
            file_put_contents(
                $temporaryLock,
                json_encode(['schema_version' => 1, 'plugins' => [$lockEntry]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
                LOCK_EX,
            );
            $descriptor = PluginLockResolver::forPackageArchive($this->serverRoot, $stageRoot, $packageKey);
            unlink($temporaryLock);

            return new VerifiedPluginPackage(
                $archiveDigest,
                $packageKey,
                $packageVersion,
                $stageRoot,
                $manifestRelative,
                $descriptor,
                $inventory,
                $modules,
                $dependencyOrder,
            );
        } catch (\Throwable $exception) {
            $this->removeTree($stageRoot);
            if ($exception instanceof PluginPackageException) {
                throw $exception;
            }
            if ($exception instanceof PluginLifecycleException) {
                throw new PluginPackageException($exception->errorCode, 'Package identity validation failed.', 0, $exception);
            }
            throw new PluginPackageException('MODULE_PACKAGE_PREFLIGHT_FAILED', 'Package preflight failed.', 0, $exception);
        }
    }

    public function cleanup(VerifiedPluginPackage $package): void
    {
        $this->removeTree($package->stageRoot);
    }

    /**
     * @param list<string> $moduleKeys
     * @param array{key_id:string,secret_key:string}|null $signer
     * @return array{path:string,package_key:string,version:string,sha256:string,modules:list<string>}
     */
    private function pack(
        string $packageKey,
        string $version,
        array $moduleKeys,
        string $outputPath,
        ?array $signer,
    ): array {
        $built = $this->packageEntries($packageKey, $version, $moduleKeys);
        $moduleKeys = $built['module_keys'];
        $entries = $built['entries'];
        $inventory = $built['inventory'];
        $entries['META-INF/files.sha256'] = ['contents' => $inventory];
        if ($signer !== null) {
            $signature = $this->signatureDocument($packageKey, $version, $inventory, $signer);
            $entries['META-INF/signatures/' . $signature['key_id'] . '.json'] = [
                'contents' => json_encode($signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            ];
        }
        $this->tar->write($outputPath, $entries);
        $digest = hash_file('sha256', $outputPath);
        if (!is_string($digest)) {
            throw new PluginPackageException('MODULE_PACKAGE_WRITE_FAILED', 'Package archive digest failed.');
        }
        return [
            'path' => $outputPath,
            'package_key' => $packageKey,
            'version' => $version,
            'sha256' => $digest,
            'modules' => $moduleKeys,
        ];
    }

    /**
     * @param list<string> $moduleKeys
     * @return array{
     *   module_keys:list<string>,
     *   entries:array<string,array{source?:string,contents?:string}>,
     *   inventory:string
     * }
     */
    private function packageEntries(string $packageKey, string $version, array $moduleKeys): array
    {
        $moduleKeys = array_values(array_unique(array_map('trim', $moduleKeys)));
        sort($moduleKeys, SORT_STRING);
        if ($moduleKeys === []) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Package contains no Modules.');
        }
        if (count($moduleKeys) === 1 && $moduleKeys[0] !== $packageKey) {
            throw new PluginPackageException('MODULE_PACKAGE_SINGLE_IDENTITY_INVALID', 'Single-Module package key must equal its Module key.');
        }
        if (count($moduleKeys) > 1 && in_array($packageKey, $moduleKeys, true)) {
            throw new PluginPackageException('MODULE_PACKAGE_BUNDLE_IDENTITY_INVALID', 'Bundle key must not impersonate a member Module.');
        }

        $preflight = new PackageCheck($this->projectRoot());
        $modules = [];
        $moduleSpecs = [];
        $availableVersions = [];
        $inspections = [];
        foreach ($moduleKeys as $moduleKey) {
            $inspection = $preflight->inspect($moduleKey);
            $inspections[$moduleKey] = $inspection;
            $modules[$moduleKey] = [
                'version' => $inspection['version'],
                'dependencies' => $inspection['dependencies'],
            ];
            $availableVersions[$moduleKey] = $inspection['version'];
            foreach ($inspection['dependencies'] as $dependency) {
                $availableVersions[$dependency['module_key']] ??= $this->localModuleVersion($dependency['module_key']);
            }
            $moduleSpecs[] = $moduleKey . '=' . $inspection['backend_relative'];
        }
        $preflight->dependencyOrder($modules, array_filter($availableVersions, 'is_string'));

        try {
            $manifest = (new PluginArtifactWriter($this->serverRoot))->build($packageKey, $version, $moduleSpecs);
        } catch (PluginArtifactToolException $exception) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Plugin manifest generation failed.', 0, $exception);
        }
        $manifestRelative = 'plugins/' . $packageKey . '/plugin.json';
        $entries = [
            $manifestRelative => [
                'contents' => json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . "\n",
            ],
        ];
        foreach ($inspections as $inspection) {
            $this->collectFiles($inspection['backend_relative'], $entries);
            foreach ($inspection['frontend_contributions'] as $contribution) {
                $this->collectFiles($contribution['root'], $entries);
            }
        }
        ksort($entries, SORT_STRING);
        $inventory = '';
        foreach ($entries as $path => $entry) {
            $digest = isset($entry['source'])
                ? hash_file('sha256', (string) $entry['source'])
                : hash('sha256', (string) $entry['contents']);
            if (!is_string($digest)) {
                throw new PluginPackageException('MODULE_PACKAGE_SOURCE_INVALID', 'Package source digest failed.');
            }
            $inventory .= $path . "\0" . $digest . "\n";
        }
        return ['module_keys' => $moduleKeys, 'entries' => $entries, 'inventory' => $inventory];
    }

    /** @param array<string,array{offset:int,size:int}> $entries @param array<string,string> $trustedPublicKeys */
    private function verifySignature(
        string $archive,
        array $entries,
        string $inventory,
        array $trustedPublicKeys,
        ?string $requestedKeyId,
        bool $required,
    ): ?string {
        if ($requestedKeyId !== null && preg_match('/^[A-Za-z0-9._-]{1,96}$/D', $requestedKeyId) !== 1) {
            throw new PluginPackageException('MODULE_PACKAGE_SIGNATURE_INVALID', 'Package signature key id is invalid.');
        }
        $candidates = [];
        foreach ($entries as $path => $entry) {
            if (preg_match('#^META-INF/signatures/([A-Za-z0-9._-]{1,96})\.json$#D', $path, $match) === 1) {
                $candidates[$match[1]] = $entry;
            }
        }
        $keys = $requestedKeyId !== null ? [$requestedKeyId] : array_keys($candidates);
        sort($keys, SORT_STRING);
        foreach ($keys as $keyId) {
            if (!isset($candidates[$keyId], $trustedPublicKeys[$keyId])) {
                continue;
            }
            try {
                $document = json_decode($this->tar->read($archive, $candidates[$keyId]), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new PluginPackageException('MODULE_PACKAGE_SIGNATURE_INVALID', 'Package signature document is invalid.');
            }
            $expectedKeys = ['algorithm', 'inventory_sha256', 'key_id', 'package_key', 'package_version', 'schema_version', 'signature_base64'];
            if (!is_array($document) || array_is_list($document)) {
                throw new PluginPackageException('MODULE_PACKAGE_SIGNATURE_INVALID', 'Package signature document is invalid.');
            }
            $actualKeys = array_keys($document);
            sort($actualKeys, SORT_STRING);
            if ($actualKeys !== $expectedKeys || ($document['schema_version'] ?? null) !== 1
                || ($document['algorithm'] ?? null) !== 'ed25519' || ($document['key_id'] ?? null) !== $keyId) {
                throw new PluginPackageException('MODULE_PACKAGE_SIGNATURE_INVALID', 'Package signature document is invalid.');
            }
            $inventoryDigest = hash('sha256', $inventory);
            if (!is_string($document['inventory_sha256'] ?? null)
                || !hash_equals($inventoryDigest, $document['inventory_sha256'])) {
                throw new PluginPackageException('MODULE_PACKAGE_SIGNATURE_INVALID', 'Package signature inventory digest differs.');
            }
            $signature = base64_decode((string) ($document['signature_base64'] ?? ''), true);
            $publicKey = $trustedPublicKeys[$keyId];
            if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || !sodium_crypto_sign_verify_detached($signature, hash('sha256', $inventory, true), $publicKey)) {
                throw new PluginPackageException('MODULE_PACKAGE_SIGNATURE_INVALID', 'Package signature verification failed.');
            }
            return $keyId;
        }
        if ($requestedKeyId !== null || $required) {
            throw new PluginPackageException('MODULE_PACKAGE_SOURCE_UNTRUSTED', 'Package trusted signature is unavailable.');
        }
        return null;
    }

    /** @param array{offset:int,size:int} $entry */
    private function assertSignatureIdentity(
        string $archive,
        array $entry,
        string $packageKey,
        string $packageVersion,
    ): void {
        try {
            $document = json_decode($this->tar->read($archive, $entry), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginPackageException('MODULE_PACKAGE_SIGNATURE_INVALID', 'Package signature document is invalid.');
        }
        if (!is_array($document) || ($document['package_key'] ?? null) !== $packageKey
            || ($document['package_version'] ?? null) !== $packageVersion) {
            throw new PluginPackageException('MODULE_PACKAGE_SIGNATURE_INVALID', 'Package signature identity differs.');
        }
    }

    /** @param array<string,array{offset:int,size:int}> $entries @return array<string,string> */
    private function verifyInventory(string $stageRoot, array $entries, string $contents): array
    {
        $inventory = [];
        $previous = null;
        $lines = explode("\n", $contents);
        if (array_pop($lines) !== '') {
            throw new PluginPackageException('MODULE_PACKAGE_INVENTORY_INVALID', 'Package inventory must end with LF.');
        }
        foreach ($lines as $line) {
            $separator = strpos($line, "\0");
            if ($separator === false || strpos($line, "\0", $separator + 1) !== false) {
                throw new PluginPackageException('MODULE_PACKAGE_INVENTORY_INVALID', 'Package inventory row is invalid.');
            }
            $path = substr($line, 0, $separator);
            $digest = substr($line, $separator + 1);
            if ($path === '' || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || isset($inventory[$path]) || ($previous !== null && strcmp($previous, $path) >= 0)) {
                throw new PluginPackageException('MODULE_PACKAGE_INVENTORY_INVALID', 'Package inventory is not canonical.');
            }
            $inventory[$path] = $digest;
            $previous = $path;
        }
        $payloadPaths = array_values(array_filter(
            array_keys($entries),
            static fn(string $path): bool => $path !== 'META-INF/files.sha256'
                && !str_starts_with($path, 'META-INF/signatures/'),
        ));
        sort($payloadPaths, SORT_STRING);
        if ($payloadPaths !== array_keys($inventory)) {
            throw new PluginPackageException('MODULE_PACKAGE_INVENTORY_INVALID', 'Package inventory coverage differs from payload.');
        }
        foreach ($inventory as $path => $digest) {
            $actual = hash_file('sha256', $stageRoot . '/' . $path);
            if (!is_string($actual) || !hash_equals($digest, $actual)) {
                throw new PluginPackageException('MODULE_PACKAGE_FILE_DIGEST_MISMATCH', 'Package payload digest differs.');
            }
        }
        return $inventory;
    }

    /** @param array<string,string> $inventory @return array{string,string,array<string,mixed>} */
    private function pluginManifest(string $stageRoot, array $inventory): array
    {
        $matches = [];
        foreach (array_keys($inventory) as $path) {
            if (preg_match('#^plugins/([a-z][a-z0-9]*(?:[.-][a-z0-9]+)*)/plugin\.json$#D', $path, $match) === 1) {
                $matches[] = [$match[1], $path];
            }
        }
        if (count($matches) !== 1) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Package must contain exactly one Plugin manifest.');
        }
        [$key, $path] = $matches[0];
        try {
            $manifest = json_decode((string) file_get_contents($stageRoot . '/' . $path), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Plugin manifest JSON is invalid.');
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Plugin manifest root is invalid.');
        }
        return [$key, $path, $manifest];
    }

    /** @param array<string,mixed> $manifest */
    private function assertPluginSchema(array $manifest): void
    {
        try {
            $schema = json_decode((string) file_get_contents($this->serverRoot . '/resources/schemas/plugin.schema.json'));
            $document = json_decode(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $result = (new Validator())->validate($document, $schema);
        } catch (\JsonException $exception) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Plugin schema cannot be evaluated.', 0, $exception);
        }
        if (!$result->isValid()) {
            $details = $result->error() === null ? [] : (new ErrorFormatter())->formatKeyed($result->error());
            throw new PluginPackageException(
                'MODULE_PACKAGE_MANIFEST_INVALID',
                'Plugin manifest schema validation failed: ' . json_encode($details, JSON_UNESCAPED_SLASHES),
            );
        }
    }

    /** @param array<string,array{source?:string,contents?:string}> $entries */
    private function collectFiles(string $relativeRoot, array &$entries): void
    {
        $projectRoot = realpath($this->projectRoot());
        if ($projectRoot === false) {
            throw new PluginPackageException('MODULE_PACKAGE_SOURCE_INVALID', 'Module package project root is unavailable.');
        }
        $absoluteRoot = $projectRoot . '/' . $relativeRoot;
        if (realpath($absoluteRoot) !== $absoluteRoot || !is_dir($absoluteRoot)
            || !str_starts_with($absoluteRoot, $projectRoot . '/')) {
            throw new PluginPackageException('MODULE_PACKAGE_SOURCE_INVALID', 'Module package source root is not an ordinary project directory.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    if ($file->isLink()) {
                        throw new PluginPackageException('MODULE_PACKAGE_SOURCE_INVALID', 'Module package source contains a link.');
                    }
                    return !$file->isDir() || !in_array(strtolower($file->getFilename()), PluginArtifactWriter::EXCLUDED_SOURCE_DIRECTORIES, true);
                },
            ),
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                throw new PluginPackageException('MODULE_PACKAGE_SOURCE_INVALID', 'Module package source contains an unsupported member.');
            }
            $relative = ltrim(substr($file->getPathname(), strlen($projectRoot)), '/');
            $this->assertPublishableSourcePath($relative);
            if (isset($entries[$relative])) {
                throw new PluginPackageException('MODULE_PACKAGE_DUPLICATE_PATH', 'Module package source path is duplicated.');
            }
            $entries[$relative] = ['source' => $file->getPathname()];
        }
    }

    private function assertPublishableSourcePath(string $relative): void
    {
        $segments = array_map('strtolower', explode('/', $relative));
        $basename = (string) end($segments);
        $forbiddenDirectories = [
            '.git', '.hg', '.idea', '.local', '.svn', '.vscode',
        ];
        foreach (array_slice($segments, 0, -1) as $segment) {
            if (in_array($segment, $forbiddenDirectories, true)) {
                throw new PluginPackageException(
                    'MODULE_PACKAGE_SOURCE_FORBIDDEN',
                    'Module package source contains a Host, dependency or workspace directory.',
                );
            }
        }
        $forbiddenFiles = ['.npmrc', '.yarnrc', 'auth.json', 'credentials.json', 'secrets.json'];
        if (str_starts_with($basename, '.env')
            || in_array($basename, $forbiddenFiles, true)
            || preg_match('/\.(?:jks|key|keystore|p12|pem|pfx)$/D', $basename) === 1
            || preg_match('/^id_(?:dsa|ecdsa|ed25519|rsa)(?:\.pub)?$/D', $basename) === 1) {
            throw new PluginPackageException(
                'MODULE_PACKAGE_SOURCE_FORBIDDEN',
                'Module package source contains an environment or credential file.',
            );
        }
    }

    /** @param array{key_id:string,secret_key:string} $signer @return array<string,mixed> */
    private function signatureDocument(string $packageKey, string $version, string $inventory, array $signer): array
    {
        $keyId = trim($signer['key_id'] ?? '');
        $secretKey = $signer['secret_key'] ?? '';
        if (preg_match('/^[A-Za-z0-9._-]{1,96}$/D', $keyId) !== 1
            || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new PluginPackageException('MODULE_PACKAGE_SIGNING_KEY_INVALID', 'Package signing key is invalid.');
        }
        return [
            'schema_version' => 1,
            'algorithm' => 'ed25519',
            'key_id' => $keyId,
            'package_key' => $packageKey,
            'package_version' => $version,
            'inventory_sha256' => hash('sha256', $inventory),
            'signature_base64' => base64_encode(sodium_crypto_sign_detached(hash('sha256', $inventory, true), $secretKey)),
        ];
    }

    private function localModuleVersion(string $moduleKey): ?string
    {
        try {
            $key = ModuleKey::fromString($moduleKey);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $layout = ModuleHostLayoutFactory::pathLayout();
        $path = $this->projectRoot() . '/' . $layout->backendRelativePath($key) . 'module.json';
        if (!is_file($path)) {
            return null;
        }
        try {
            $manifest = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }

    private function projectRoot(): string
    {
        return dirname($this->serverRoot);
    }
}
