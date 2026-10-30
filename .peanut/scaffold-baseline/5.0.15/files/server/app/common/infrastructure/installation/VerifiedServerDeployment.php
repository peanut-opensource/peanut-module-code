<?php

declare(strict_types=1);

namespace app\common\infrastructure\installation;

use app\common\http\RequestTrace;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\common\value\installation\ServerReleaseIdentity;
use app\common\value\installation\ApplicationSourceIdentity;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use RuntimeException;
use think\App;
use think\cache\driver\File;

/** Deployment-owner admission of pure declarations into ThinkPHP's native File store.
 * File::set is not atomic: write an immutable key under the instance lock, fsync it,
 * then atomically publish the pointer while traffic is closed. HTTP never compiles.
 */
final readonly class VerifiedServerDeployment
{
    private const FORMAT = 'peanut.verified-server-deployment.v1';
    private const DIRECTORY = '/runtime/upgrade/verified-deployment';

    private function __construct(private string $serverRoot, private array $record, private ?ReadonlyHttpMount $httpMount = null) {}

    public static function read(App $app): self
    {
        $started = class_exists(RequestTrace::class, false) ? RequestTrace::beginDeploymentTiming() : null;
        try {
            $root = rtrim($app->getRootPath(), '/');
            if (!is_file($root . '/runtime/upgrade/.traffic-ready')) {
                throw new RuntimeException('SERVER_DEPLOYMENT_TRAFFIC_CLOSED');
            }
            $httpMount = ReadonlyHttpMount::active($root) ? ReadonlyHttpMount::read($root) : null;
            if ($httpMount === null) {
                ReadonlyHttpMount::assertUnprivilegedWorker($root);
            }
            self::assertProtectedPath($root, 'runtime/upgrade/.traffic-ready', $httpMount);
            if (self::bytes($root . '/runtime/upgrade/.traffic-ready') !== "peanut.server-traffic-ready.v1\n") {
                throw new RuntimeException('SERVER_DEPLOYMENT_TRAFFIC_CLOSED');
            }
            self::assertProtectedPath($root, 'runtime/upgrade/verified-deployment/current.json', $httpMount);
            $inputs = $httpMount?->admissionInputs();
            $pointer = self::json($inputs !== null ? $inputs['pointer'] : self::bytes($root . self::DIRECTORY . '/current.json'));
            if (($pointer['format'] ?? null) !== self::FORMAT
                || !is_string($pointer['key'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $pointer['key']) !== 1
                || !is_string($pointer['sha256'] ?? null)) {
                throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
            }
            $store = self::store($app);
            $file = $store->getCacheKey($pointer['key']);
            self::assertProtectedPath($root, substr($file, strlen($root) + 1), $httpMount);
            if (!hash_equals($pointer['sha256'], hash('sha256', self::bytes($file)))) {
                throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
            }
            $record = $store->get($pointer['key']);
            if (!is_array($record) || ($record['format'] ?? null) !== self::FORMAT
                || ($record['binding'] ?? null) !== self::binding($root, $app->config->get('modules', []), $httpMount)
                || !is_array($record['identity'] ?? null) || !is_array($record['registry'] ?? null)) {
                throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_MISMATCH');
            }
            return new self($root, $record, $httpMount);
        } finally {
            if ($started !== null) {
                RequestTrace::finishDeploymentReadTiming($started);
            }
        }
    }

    /** Called by the native startup owner before granting traffic. */
    public static function publish(App $app, ServerReleaseIdentity|ApplicationSourceIdentity $identity): void
    {
        $root = rtrim($app->getRootPath(), '/');
        $httpMount = self::assertImmutableProgram($root);
        $registry = $app->make(CompiledModuleRegistry::class);
        $modules = [];
        foreach ($registry->modules as $manifest) {
            $relative = self::relative($root, $manifest->root);
            self::assertProtectedPath($root, $relative . '/module.json', $httpMount);
            $modules[] = ['root' => $relative, 'json' => json_encode($manifest->object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
        }
        $binding = self::binding($root, $app->config->get('modules', []), $httpMount);
        if (!hash_equals($identity->identitySha256(), $binding['identity'])) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_MISMATCH');
        }
        $plugins = [];
        foreach (\app\platform\infrastructure\plugin\PluginLockResolver::forRuntime(
            $root, (string) $app->config->get('modules.plugin_lock'),
        )->all() as $key => $plugin) {
            $plugins[$key] = (array) $plugin;
            $plugins[$key]['moduleRoots'] = array_map(static fn(string $path): string => self::relative($root, $path), $plugin->moduleRoots);
        }
        $record = ['format' => self::FORMAT, 'binding' => $binding, 'identity' => $identity->document(),
            'plugins' => $plugins,
            'registry' => ['modules' => $modules, 'targetTypeOwners' => $registry->targetTypeOwners,
                'ownedTableOwners' => $registry->ownedTableOwners, 'menus' => $registry->menus,
                'revision' => $registry->revision]];
        $directory = $root . self::DIRECTORY;
        if (!file_exists($directory) && !mkdir($directory, 0755)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
        }
        self::assertProtectedPath($root, 'runtime/upgrade/verified-deployment', $httpMount);
        // The owner inherits the secret-file umask. Workers must still traverse
        // this protected declaration directory; its files keep their own modes.
        if (!chmod($directory, 0755)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
        }
        clearstatcache(true, $directory);
        if ((fileperms($directory) & 07777) !== 0755) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
        }
        $key = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $store = self::store($app);
        $path = $store->getCacheKey($key);
        if (!file_exists($path)) {
            if (!$store->set($key, $record, 0) || !chmod($path, 0644)) {
                throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
            }
            self::sync($path);
        } else {
            self::assertProtectedPath($root, substr($path, strlen($root) + 1), $httpMount);
            if ($store->get($key) !== $record) {
                throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
            }
        }
        $pointer = json_encode(['format' => self::FORMAT, 'key' => $key,
            'sha256' => hash('sha256', self::bytes($path))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $temporary = $directory . '/current-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
        }
        try {
            if (fwrite($handle, $pointer) !== strlen($pointer) || !fflush($handle)
                || !fsync($handle) || !chmod($temporary, 0644)
                || !rename($temporary, $directory . '/current.json')) {
                throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
            }
        } finally {
            fclose($handle);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        self::sync($directory);
    }

    public function identityDocument(): array
    {
        return $this->record['identity'];
    }
    public function identityDigest(): string
    {
        return $this->record['binding']['identity'];
    }

    public function plugins(): array
    {
        $plugins = $this->record['plugins'] ?? null;
        if (!is_array($plugins)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
        }
        return array_map(function (array $plugin): \app\platform\value\plugin\PluginDescriptor {
            $plugin['moduleRoots'] = array_map(fn(string $relative): string => $this->serverRoot . '/' . $relative, $plugin['moduleRoots']);
            return new \app\platform\value\plugin\PluginDescriptor(...$plugin);
        }, $plugins);
    }

    public function registry(): CompiledModuleRegistry
    {
        $started = class_exists(RequestTrace::class, false) ? RequestTrace::beginDeploymentTiming() : null;
        try {
            $record = $this->record['registry'];
            $modules = [];
            $roots = [];
            foreach ($record['modules'] as $item) {
                if (!is_array($item) || !is_string($item['root'] ?? null) || !is_string($item['json'] ?? null)) {
                    throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
                }
                self::assertProtectedPath($this->serverRoot, $item['root'] . '/module.json', $this->httpMount);
                $object = json_decode($item['json'], false, 512, JSON_THROW_ON_ERROR);
                if (!is_object($object)) {
                    throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
                }
                $document = ManifestDocument::fromObject($this->serverRoot . '/' . $item['root'], $object);
                $modules[] = $document;
                $roots[$document->data['key']] = $this->serverRoot . '/' . $item['root'];
            }
            ModuleHostLayoutFactory::registerRuntimeAutoload($roots, $this->serverRoot);
            return new CompiledModuleRegistry(
                $modules,
                $record['targetTypeOwners'],
                $record['ownedTableOwners'],
                $record['menus'],
                $record['revision'],
            );
        } finally {
            if ($started !== null) {
                RequestTrace::finishRegistryRestoreTiming($started);
            }
        }
    }

    /** Source and dependencies must be immutable to the application user before executing Composer. */
    public static function assertImmutableProgram(string $root): ?ReadonlyHttpMount
    {
        $httpMount = ReadonlyHttpMount::ownerBoundary($root);
        $owner = fileowner($root);
        if ($httpMount === null && (!is_int($owner)
            || !in_array(posix_geteuid(), [0, $owner], true))) {
            throw new RuntimeException('SERVER_DEPLOYMENT_OWNER_REQUIRED');
        }
        $source = self::sourceApplication($root);
        $identity = [];
        if (!$source) {
            $identityPath = self::identityPath($root);
            self::assertProtectedPath($root, $identityPath, $httpMount);
            $identity = self::json(self::bytes($root . '/' . $identityPath));
        } else {
            ReadonlyHttpMount::assertSourceInputs($root);
        }
        foreach ($identity['files'] ?? [] as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !str_starts_with($file['path'], 'server/')) {
                throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
            }
            self::assertProtectedPath($root, substr($file['path'], strlen('server/')), $httpMount);
        }
        // Framework extension directories need not exist in a source application.
        // Existing objects, including broken links, still require the full protection check.
        $optionalDirectories = $source ? ['.peanut', 'bootstrap', 'extend'] : ['bootstrap', 'extend'];
        foreach (['app', 'config', 'database', '.peanut', 'resources', 'vendor', 'route', 'bootstrap', 'extend'] as $directory) {
            if (in_array($directory, $optionalDirectories, true)
                && !file_exists($root . '/' . $directory) && !is_link($root . '/' . $directory)) {
                continue;
            }
            self::assertProtectedPath($root, $directory, $httpMount);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root . '/' . $directory,
                \FilesystemIterator::SKIP_DOTS,
            ), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('SERVER_DEPLOYMENT_PROGRAM_WRITABLE: ' . $directory);
                }
                self::assertProtectedPath($root, self::relative($root, $file->getPathname()), $httpMount);
            }
        }
        foreach ([$source ? '../plugins.lock' : 'plugins.lock', 'composer.json', 'composer.lock', 'think', 'public/index.php'] as $file) {
            if ($file === '../plugins.lock') {
                continue; // The source lock is part of the verified readonly APP inputs above.
            }
            self::assertProtectedPath($root, $file, $httpMount);
        }
        return $httpMount;
    }

    private static function binding(string $root, mixed $config, ?ReadonlyHttpMount $httpMount = null): array
    {
        if (!is_array($config)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
        }
        // Host and FPM mounts may differ; only server-relative Module roots are admitted.
        foreach ($config['roots'] ?? [] as $index => $path) {
            $absolute = str_starts_with($path, '/') ? $path : $root . '/' . $path;
            $config['roots'][$index] = self::relative($root, $absolute);
        }
        $lock = $config['plugin_lock'] ?? null;
        $source = self::sourceApplication($root);
        $expectedLock = $source ? '../plugins.lock' : 'plugins.lock';
        if ($lock !== $expectedLock && $lock !== $root . '/' . $expectedLock) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
        }
        $config['plugin_lock'] = $expectedLock;
        $binding = ['modules' => hash('sha256', json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'program_authority' => $httpMount !== null ? ['boundary' => 'native-readonly-http']
                : ['boundary' => 'unix-dac', 'deployment_owner' => fileowner($root), 'root_mode' => fileperms($root) & 07777]];
        $inputs = $httpMount?->admissionInputs();
        if ($source) {
            ReadonlyHttpMount::assertSourceInputs($root, $httpMount);
            $binding['identity'] = $inputs !== null ? $inputs['identity'] : ApplicationSourceIdentity::sourceDigest($root);
            $binding['lock'] = hash('sha256', self::bytes(dirname($root) . '/plugins.lock'));
        }
        foreach (['identity' => $source ? null : self::identityPath($root), 'lock' => $source ? null : 'plugins.lock',
            'dependencies' => 'composer.lock', 'installed_dependencies' => 'vendor/composer/installed.json'] as $key => $path) {
            if ($path === null) { continue; }
            self::assertProtectedPath($root, $path, $httpMount);
            $binding[$key] = $key === 'identity' && $inputs !== null
                ? $inputs['identity'] : hash('sha256', self::bytes($root . '/' . $path));
        }
        if (ApplicationSourceIdentity::hasRuntime($root)) {
            self::assertProtectedPath($root, 'vendor/.peanut-complete.json', $httpMount);
            $binding['vendor_seal'] = hash('sha256', self::bytes($root . '/vendor/.peanut-complete.json'));
        }
        foreach (['.env', 'private/resources/project-resources.json', 'private/resources/configuration.json'] as $file) {
            $path = $root . '/' . $file;
            if ($httpMount !== null && (file_exists($path) || is_link($path))) {
                self::assertProtectedPath($root, $file, $httpMount);
            }
            $binding[$file] = file_exists($path) || is_link($path) ? hash('sha256', self::bytes($path)) : null;
        }
        foreach (['installed' => 'installed.json', 'baseline' => 'baseline.json', 'deployment' => 'deployment.json'] as $key => $file) {
            foreach (['private', 'private/installation'] as $directory) {
                if (!is_dir($root . '/' . $directory) || is_link($root . '/' . $directory)) {
                    throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_PATH_INVALID');
                }
            }
            $path = $root . '/private/installation/' . $file;
            $bytes = $key === 'deployment' && $inputs !== null ? $inputs['deployment']
                : (file_exists($path) || is_link($path) ? self::bytes($path) : null);
            if ($key === 'deployment' && $bytes !== null
                && (self::json($bytes)['protocol'] ?? null) === 'peanut.application-deployment.v1') {
                // Installation is an rw submount: its canonical head additionally requires owner DAC protection.
                self::assertProtectedPath($root, 'private/installation/deployment.json');
            }
            $binding[$key] = $key === 'deployment' && $inputs !== null ? $inputs['deployment_sha256']
                : ($bytes !== null ? hash('sha256', $bytes) : null);
        }
        return $binding;
    }

    public static function identityPath(string $root): string
    {
        if (ApplicationSourceIdentity::hasRuntime($root)) {
            if (file_exists($root . '/.peanut/release-identity.json') || is_link($root . '/.peanut/release-identity.json')) {
                throw new RuntimeException('SERVER_DEPLOYMENT_IDENTITY_CONFLICT');
            }
            return ApplicationSourceIdentity::RUNTIME_PATH;
        }
        return '.peanut/release-identity.json';
    }

    public static function sourceApplication(string $root): bool
    {
        return !ApplicationSourceIdentity::hasRuntime($root)
            && !file_exists($root . '/.peanut/release-identity.json')
            && !is_link($root . '/.peanut/release-identity.json');
    }

    public static function inputIdentityDigest(string $root): string
    {
        return self::sourceApplication($root) ? ApplicationSourceIdentity::sourceDigest($root)
            : hash('sha256', self::bytes($root . '/' . self::identityPath($root)));
    }

    private static function store(App $app): File
    {
        return new File($app, ['path' => rtrim($app->getRootPath(), '/') . self::DIRECTORY,
            'prefix' => '', 'cache_subdir' => false, 'expire' => 0,
            'serialize' => [static fn(mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                static fn(string $value): array => self::json($value)]]);
    }

    private static function relative(string $root, string $path): string
    {
        $canonical = realpath($path);
        if ($canonical === false || !str_starts_with($canonical, rtrim($root, '/') . '/')) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_PATH_INVALID');
        }
        return substr($canonical, strlen(rtrim($root, '/')) + 1);
    }

    private static function assertProtectedPath(string $root, string $relative, ?ReadonlyHttpMount $httpMount = null): void
    {
        $parts = explode('/', $relative);
        if (array_intersect($parts, ['', '.', '..']) !== [] || str_contains($relative, '\\')) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_PATH_INVALID');
        }
        if ($httpMount !== null) {
            // The verified native HTTP mount hides owner-only Docker inputs.
            // Validate their original paths with the same owner DAC contract;
            // full release byte verification still consumes the owner view.
            if ($root === '/run/peanut-owner/server'
                && ($relative === 'docker' || str_starts_with($relative, 'docker/'))) {
                self::assertProtectedPath($root, $relative);
                return;
            }
            $httpMount->assertPath($relative);
            return;
        }
        $owner = fileowner($root);
        if (!is_int($owner)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_OWNER_REQUIRED');
        }
        // A protected child is insufficient when the application can replace its mount parent.
        for ($parent = dirname($root); ; $parent = dirname($parent)) {
            $stat = @lstat($parent);
            if (!is_array($stat) || !is_dir($parent) || is_link($parent)
                || !in_array($stat['uid'], [0, $owner], true)
                || (($stat['mode'] & 0022) !== 0 && ($stat['mode'] & 01000) === 0)) {
                throw new RuntimeException('SERVER_DEPLOYMENT_PROGRAM_WRITABLE: parent');
            }
            if ($parent === '/') {
                break;
            }
        }
        $cursor = $root;
        foreach (['', ...$parts] as $index => $part) {
            if ($index > 0) {
                $cursor .= '/' . $part;
            }
            clearstatcache(true, $cursor);
            $stat = @lstat($cursor);
            $isDirectory = is_dir($cursor);
            $stickyWindow = ($cursor === $root || $cursor === $root . '/runtime')
                && $isDirectory && is_array($stat) && ($stat['mode'] & 01000) !== 0
                && ($stat['mode'] & 0002) === 0;
            if (!is_array($stat) || is_link($cursor) || (!$isDirectory && !is_file($cursor))
                || !in_array($stat['uid'], [0, $owner], true) || (!$isDirectory && $stat['nlink'] !== 1)
                || (($stat['mode'] & 0022) !== 0 && !$stickyWindow)) {
                throw new RuntimeException('SERVER_DEPLOYMENT_PROGRAM_WRITABLE: ' . $relative);
            }
        }
    }

    private static function bytes(string $path): string
    {
        $stat = @lstat($path);
        if (!is_array($stat) || !is_file($path) || is_link($path) || $stat['nlink'] !== 1) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
        }
        return $bytes;
    }

    private static function json(string $bytes): array
    {
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_INVALID');
        }
        return $value;
    }

    private static function sync(string $path): void
    {
        $handle = fopen($path, 'r');
        if (!is_resource($handle) || !fsync($handle)) {
            throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
        }
        fclose($handle);
    }
}
