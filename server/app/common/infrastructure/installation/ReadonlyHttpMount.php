<?php

declare(strict_types=1);

namespace app\common\infrastructure\installation;

use RuntimeException;

/** Native FPM chroot admission, produced by the existing root entrypoint. */
final readonly class ReadonlyHttpMount
{
    public const CHROOT = '/var/www/peanut-http';
    private const CONTEXT = '/run/peanut-http/context.json';
    public const MUTABLE = ['private/installation', 'private/storage', 'private/resources/pending',
        'public/storage', 'runtime/cache', 'runtime/log', 'runtime/session', 'runtime/temp', 'runtime/storage', 'runtime/generator', 'runtime/file'];
    private const SOURCE_INPUTS = ['.peanut/application-manifest.json', 'release-versions.json', 'plugins.lock', 'RELEASE_METADATA.json'];

    private function __construct(private string $root, private ?array $inputs = null) {}

    public static function active(string $root): bool
    {
        return PHP_SAPI === 'fpm-fcgi' && $root === '/server';
    }

    public static function ownerBoundary(string $root): ?self
    {
        if ($root !== '/run/peanut-owner/server') {
            return null;
        }
        self::verifyOwnerMounts($root);
        return new self(self::CHROOT . '/server');
    }

    /** Same kernel proof for pre-execution full verification and final worker admission. */
    private static function verifyOwnerMounts(string $ownerRoot): void
    {
        if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
            throw new RuntimeException('HTTP_MOUNT_OWNER_REQUIRED');
        }
        $legacy = $ownerRoot . '/private/installation/update-verification.key';
        if (file_exists($legacy) || is_link($legacy)) {
            throw new RuntimeException('HTTP_OWNER_MAINTENANCE_REQUIRED');
        }
        $root = self::CHROOT . '/server';
        $mounts = self::mounts((string) file_get_contents('/proc/self/mountinfo'));
        $base = self::effective($mounts, $root);
        if ($base['path'] !== $root || !$base['readonly']) {
            throw new RuntimeException('HTTP_PROGRAM_MOUNT_NOT_READONLY');
        }
        $ownerStat = lstat($ownerRoot);
        $httpStat = lstat($root);
        if ($ownerStat['dev'] !== $httpStat['dev'] || $ownerStat['ino'] !== $httpStat['ino']) {
            throw new RuntimeException('HTTP_PROGRAM_MOUNT_SOURCE_MISMATCH');
        }
        $sourceIni = @lstat($ownerRoot . '/docker/conf/php.ini');
        $httpIni = @lstat(self::CHROOT . '/usr/local/etc/php/conf.d/zz-peanut.ini');
        if (!is_array($sourceIni) || !is_array($httpIni) || $sourceIni['dev'] !== $httpIni['dev'] || $sourceIni['ino'] !== $httpIni['ino']) {
            throw new RuntimeException('HTTP_IMAGE_CONFIGURATION_SOURCE_MISMATCH');
        }
        $seen = [];
        $imageMounts = [self::CHROOT . '/usr/local/etc/php/conf.d/zz-peanut.ini',
            self::CHROOT . '/run/peanut-admin/resource-lease', self::CHROOT . '/run/peanut-admin/resource-lease-parent'];
        foreach (self::SOURCE_INPUTS as $relative) {
            $imageMounts[] = self::CHROOT . '/' . $relative;
        }
        foreach ($mounts as $mount) {
            if ($mount['path'] === self::CHROOT || str_starts_with($mount['path'], self::CHROOT . '/')) {
                if (isset($seen[$mount['path']])) {
                    throw new RuntimeException('HTTP_MOUNT_TABLE_AMBIGUOUS');
                }
                $seen[$mount['path']] = true;
                if ($mount['path'] !== $root && !str_starts_with($mount['path'], $root . '/')) {
                    if (!in_array($mount['path'], $imageMounts, true) || !$mount['readonly']) {
                        throw new RuntimeException('HTTP_IMAGE_SUBMOUNT_INVALID');
                    }
                    continue;
                }
            }
            if (!str_starts_with($mount['path'], $root . '/')) {
                continue;
            }
            $relative = substr($mount['path'], strlen($root) + 1);
            if (in_array($relative, ['docker', 'private/maintenance'], true) && $mount['readonly']) {
                continue;
            }
            if (!in_array($relative, self::MUTABLE, true) || $mount['readonly']) {
                throw new RuntimeException('HTTP_PROGRAM_SUBMOUNT_INVALID: ' . $relative);
            }
        }
        if (is_readable($root . '/docker/.env') || is_readable($root . '/docker/secrets')
            || self::effective($mounts, $root . '/docker')['path'] !== $root . '/docker'
            || !self::effective($mounts, $root . '/docker')['readonly']) {
            throw new RuntimeException('HTTP_OWNER_CREDENTIALS_EXPOSED');
        }
        if (is_readable($root . '/private/maintenance/update-verification.key')
            || self::effective($mounts, $root . '/private/maintenance')['path'] !== $root . '/private/maintenance'
            || !self::effective($mounts, $root . '/private/maintenance')['readonly']) {
            throw new RuntimeException('HTTP_OWNER_CREDENTIALS_EXPOSED');
        }
        foreach (self::MUTABLE as $relative) {
            $path = $root . '/' . $relative;
            $source = @lstat($ownerRoot . '/' . $relative);
            $target = @lstat($path);
            if (self::effective($mounts, $path)['path'] !== $path || self::effective($mounts, $path)['readonly']
                || is_link($path) || is_link($ownerRoot . '/' . $relative) || !is_dir($path)
                || !is_array($source) || !is_array($target) || $source['dev'] !== $target['dev'] || $source['ino'] !== $target['ino']) {
                throw new RuntimeException('HTTP_MUTABLE_MOUNT_INVALID: ' . $relative);
            }
        }
        // Bind mounts cannot be renamed by an unprivileged writer in their parent.
        // Reject every unexpected deeper mount, which could otherwise re-enable program writes.
        $directory = self::CHROOT . dirname(self::CONTEXT);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new RuntimeException('HTTP_MOUNT_CONTEXT_UNAVAILABLE');
        }
        self::assertImagePath('/', $directory);
        if (lstat($directory)['dev'] === $httpStat['dev']) {
            throw new RuntimeException('HTTP_IMAGE_AUTHORITY_ON_PROGRAM_MOUNT');
        }
        // Bootstrap secrets keep umask 077; only this verified image-owned directory
        // needs worker traversal to read the separately published 0444 context.
        if (!chmod($directory, 0755)) {
            throw new RuntimeException('HTTP_MOUNT_CONTEXT_UNAVAILABLE');
        }
    }

    public static function publish(string $ownerRoot): void
    {
        self::verifyOwnerMounts($ownerRoot);
        $root = self::CHROOT . '/server';
        $httpStat = lstat($root);
        $mounts = self::mounts((string) file_get_contents('/proc/self/mountinfo'));
        $directory = self::CHROOT . dirname(self::CONTEXT);
        $pointer = $root . '/runtime/upgrade/verified-deployment/current.json';
        $paths = [$pointer];
        if (VerifiedServerDeployment::sourceApplication($root)) {
            self::assertSourceInputs($ownerRoot);
        } else {
            $paths[] = $root . '/' . VerifiedServerDeployment::identityPath($root);
        }
        foreach ($paths as $path) {
            if (is_link($path) || !is_file($path) || !self::effective($mounts, $path)['readonly']) {
                throw new RuntimeException('HTTP_MOUNT_ADMISSION_UNAVAILABLE');
            }
        }
        $worker = posix_getpwnam('www-data');
        $context = ['worker_uid' => $worker['uid'], 'worker_gid' => $worker['gid'], 'protocol' => 'peanut.native-readonly-http.v1', 'root' => '/server',
            'device' => $httpStat['dev'], 'inode' => $httpStat['ino'],
            'identity_sha256' => VerifiedServerDeployment::inputIdentityDigest($root), 'pointer_sha256' => hash_file('sha256', $pointer),
            'deployment_sha256' => is_file($ownerRoot . '/private/installation/deployment.json')
                ? hash_file('sha256', $ownerRoot . '/private/installation/deployment.json') : null];
        $target = self::CHROOT . self::CONTEXT;
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('HTTP_MOUNT_CONTEXT_UNAVAILABLE');
        }
        try {
            $bytes = json_encode($context, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)
                || !chmod($temporary, 0444) || !rename($temporary, $target)) {
                throw new RuntimeException('HTTP_MOUNT_CONTEXT_UNAVAILABLE');
            }
            $parent = fopen($directory, 'r');
            if (!is_resource($parent)) {
                throw new RuntimeException('HTTP_MOUNT_CONTEXT_UNAVAILABLE');
            }
            try {
                if (!fsync($parent)) {
                    throw new RuntimeException('HTTP_MOUNT_CONTEXT_UNAVAILABLE');
                }
            } finally {
                fclose($parent);
            }
        } finally {
            fclose($handle);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Actual request identity, never inferred from a conventional account name. */
    public static function assertUnprivilegedWorker(string $root): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $groups = posix_getgroups();
        if ($uid === 0 || $uid === fileowner($root) || $gid === 0 || !is_array($groups) || in_array(0, $groups, true)) {
            throw new RuntimeException('HTTP_WORKER_BOUNDARY_INVALID');
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new RuntimeException('HTTP_WORKER_CAPABILITY_UNAVAILABLE');
        }
        {
            $status = @file_get_contents('/proc/self/status');
            if (!is_string($status)
                || preg_match('/^Uid:\s+' . implode('\s+', array_fill(0, 4, (string) $uid)) . '$/m', $status) !== 1
                || preg_match('/^Gid:\s+' . implode('\s+', array_fill(0, 4, (string) $gid)) . '$/m', $status) !== 1
                || preg_match('/^NoNewPrivs:\s+1$/m', $status) !== 1
                || preg_match('/^CapEff:\s+0+$/m', $status) !== 1
                || preg_match('/^CapPrm:\s+0+$/m', $status) !== 1
                || preg_match('/^CapInh:\s+0+$/m', $status) !== 1
                || preg_match('/^CapAmb:\s+0+$/m', $status) !== 1) {
                throw new RuntimeException('HTTP_WORKER_CAPABILITY_INVALID');
            }
        }
    }

    public static function read(string $root): self
    {
        if (!self::active($root) || posix_geteuid() === 0) {
            throw new RuntimeException('HTTP_WORKER_BOUNDARY_INVALID');
        }
        self::assertImagePath('/', self::CONTEXT);
        if ((lstat(self::CONTEXT)['mode'] & 0777) !== 0444) {
            throw new RuntimeException('HTTP_IMAGE_AUTHORITY_UNSAFE');
        }
        $context = json_decode((string) file_get_contents(self::CONTEXT), true, 512, JSON_THROW_ON_ERROR);
        $stat = lstat($root);
        $pointer = file_get_contents($root . '/runtime/upgrade/verified-deployment/current.json');
        $deploymentPath = $root . '/private/installation/deployment.json';
        $deployment = null;
        if (file_exists($deploymentPath) || is_link($deploymentPath)) {
            $deploymentStat = @lstat($deploymentPath);
            if (!is_array($deploymentStat) || !is_file($deploymentPath) || is_link($deploymentPath) || $deploymentStat['nlink'] !== 1) {
                throw new RuntimeException('SERVER_DEPLOYMENT_ADMISSION_UNAVAILABLE');
            }
            $deployment = file_get_contents($deploymentPath);
        }
        if (!is_string($pointer) || ($deployment !== null && !is_string($deployment))) {
            throw new RuntimeException('HTTP_MOUNT_CONTEXT_MISMATCH');
        }
        $identityDigest = VerifiedServerDeployment::inputIdentityDigest($root);
        $deploymentDigest = $deployment !== null ? hash('sha256', $deployment) : null;
        if (!is_array($context) || ($context['protocol'] ?? null) !== 'peanut.native-readonly-http.v1'
            || lstat(self::CONTEXT)['dev'] === $stat['dev']
            || ($context['worker_uid'] ?? null) !== posix_geteuid() || ($context['worker_gid'] ?? null) !== posix_getegid()
            || ($context['root'] ?? null) !== $root || ($context['device'] ?? null) !== $stat['dev']
            || ($context['inode'] ?? null) !== $stat['ino']
            || ($context['identity_sha256'] ?? null) !== $identityDigest
            || ($context['pointer_sha256'] ?? null) !== hash('sha256', $pointer)
            || ($context['deployment_sha256'] ?? null) !== $deploymentDigest) {
            throw new RuntimeException('HTTP_MOUNT_CONTEXT_MISMATCH');
        }
        return new self($root, ['identity' => $identityDigest, 'pointer' => $pointer,
            'deployment' => $deployment, 'deployment_sha256' => $deploymentDigest]);
    }

    /**
     * Request-local inputs authenticated against the image-owned mount context.
     * @return array{identity:string,pointer:string,deployment:?string,deployment_sha256:?string}|null
     */
    public function admissionInputs(): ?array
    {
        return $this->inputs;
    }

    /** Only tracked declarations cross the APP/server boundary; no Git or secrets mount. */
    public static function assertSourceInputs(string $root, ?self $httpMount = null): void
    {
        $worker = self::active($root);
        if ($worker && ($httpMount === null || $httpMount->root !== $root)) {
            throw new RuntimeException('HTTP_MOUNT_CONTEXT_MISMATCH');
        }
        // The owner proves kernel mounts before publishing admission. Chrooted
        // workers have no /proc and must already hold the authenticated readonly context.
        $mounts = $worker ? [] : self::mounts((string) file_get_contents('/proc/self/mountinfo'));
        $parent = rtrim(dirname($root), '/');
        foreach (self::SOURCE_INPUTS as $relative) {
            $path = $parent . '/' . $relative;
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path) || !is_file($path) || $stat['nlink'] !== 1
                || ($worker ? is_writable($path) : !self::effective($mounts, $path)['readonly'])) {
                throw new RuntimeException('HTTP_SOURCE_INPUT_NOT_READONLY');
            }
            if ($root === '/run/peanut-owner/server') {
                $http = @lstat(self::CHROOT . '/' . $relative);
                if (!is_array($http) || $http['dev'] !== $stat['dev'] || $http['ino'] !== $stat['ino']) {
                    throw new RuntimeException('HTTP_SOURCE_INPUT_SOURCE_MISMATCH');
                }
            }
        }
    }

    /** Rebuild the existing native module admission while the owner has closed public traffic. */
    public static function initialize(string $root): void
    {
        if ($root !== '/run/peanut-owner/server' || PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
            throw new RuntimeException('HTTP_MOUNT_OWNER_REQUIRED');
        }
        self::verifyOwnerMounts($root);
        if (file_exists($root . '/runtime/upgrade/.traffic-ready') || is_link($root . '/runtime/upgrade/.traffic-ready')) {
            throw new RuntimeException('HTTP_STARTUP_TRAFFIC_NOT_CLOSED');
        }
        $identity = VerifiedServerDeployment::sourceApplication($root)
            ? \app\common\value\installation\ApplicationSourceIdentity::loadGeneratedApp($root)
            : (\app\common\value\installation\ApplicationSourceIdentity::hasRuntime($root)
                ? \app\common\value\installation\ApplicationSourceIdentity::loadRuntime($root)
                : \app\common\value\installation\ServerReleaseIdentity::load($root));
        require_once $root . '/database/install.php';
        $app = new \think\App($root);
        $app->instance($identity::class, $identity);
        $app->initialize();
        VerifiedServerDeployment::publish($app, $identity);
        $ready = $root . '/runtime/upgrade/.traffic-ready';
        $handle = fopen($ready, 'xb');
        if (!is_resource($handle)) { throw new RuntimeException('HTTP_STARTUP_TRAFFIC_UNAVAILABLE'); }
        try {
            $bytes = "peanut.server-traffic-ready.v1\n";
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle) || !chmod($ready, 0644)) {
                throw new RuntimeException('HTTP_STARTUP_TRAFFIC_UNAVAILABLE');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function assertWorkers(int $master): void
    {
        if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0 || is_dir(self::CHROOT . '/proc')) {
            throw new RuntimeException('HTTP_WORKER_BOUNDARY_INVALID');
        }
        $expected = posix_getpwnam('www-data');
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $workers = 0;
            foreach (glob('/proc/[0-9]*/status') ?: [] as $status) {
                $bytes = @file_get_contents($status);
                if (!is_string($bytes) || preg_match('/^PPid:\s+' . $master . '$/m', $bytes) !== 1) {
                    continue;
                }
                $pid = dirname($status);
                if (preg_match('/^Uid:\s+' . implode('\s+', array_fill(0, 4, (string) $expected['uid'])) . '$/m', $bytes) !== 1
                    || preg_match('/^Gid:\s+' . implode('\s+', array_fill(0, 4, (string) $expected['gid'])) . '$/m', $bytes) !== 1
                    || preg_match('/^Groups:.*(?:^|\s)0(?:\s|$)/m', $bytes) === 1
                    || preg_match('/^CapEff:\s+0+$/m', $bytes) !== 1
                    || preg_match('/^CapPrm:\s+0+$/m', $bytes) !== 1
                    || preg_match('/^CapInh:\s+0+$/m', $bytes) !== 1
                    || preg_match('/^CapAmb:\s+0+$/m', $bytes) !== 1
                    || preg_match('/^NoNewPrivs:\s+1$/m', $bytes) !== 1
                    || readlink($pid . '/root') !== self::CHROOT
                    || readlink($pid . '/cwd') !== self::CHROOT . '/server') {
                    throw new RuntimeException('HTTP_WORKER_BOUNDARY_INVALID');
                }
                foreach (glob($pid . '/fd/*') ?: [] as $fd) {
                    $target = @readlink($fd);
                    if (is_dir($fd) || (is_string($target) && str_starts_with($target, '/run/peanut-owner/'))) {
                        throw new RuntimeException('HTTP_WORKER_INHERITED_OWNER_DESCRIPTOR');
                    }
                }
                $workers++;
            }
            if ($workers > 0) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('HTTP_WORKER_START_TIMEOUT');
    }

    public function assertPath(string $relative): void
    {
        foreach (self::MUTABLE as $path) {
            if ($relative === $path || str_starts_with($relative, $path . '/')) {
                throw new RuntimeException('HTTP_PROGRAM_PATH_MUTABLE');
            }
        }
        $cursor = $this->root;
        foreach (['', ...explode('/', $relative)] as $index => $part) {
            if ($index > 0) {
                $cursor .= '/' . $part;
            }
            $item = @lstat($cursor);
            if (!is_array($item) || is_link($cursor) || is_writable($cursor)
                || (!is_dir($cursor) && (!is_file($cursor) || $item['nlink'] !== 1))) {
                throw new RuntimeException('HTTP_PROGRAM_PATH_WRITABLE');
            }
        }
    }

    /** Image-owned Linux filesystem, outside the Docker Desktop bind mount. */
    private static function assertImagePath(string $root, string $path): void
    {
        $rootStat = @lstat($root);
        if (!is_array($rootStat) || is_link($root) || $rootStat['uid'] !== 0 || ($rootStat['mode'] & 0022) !== 0) {
            throw new RuntimeException('HTTP_IMAGE_AUTHORITY_UNSAFE');
        }
        $cursor = rtrim($root, '/');
        foreach (explode('/', ltrim(substr($path, strlen($cursor)), '/')) as $part) {
            $cursor .= '/' . $part;
            $stat = @lstat($cursor);
            if (!is_array($stat) || is_link($cursor) || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0
                || (!is_dir($cursor) && (!is_file($cursor) || $stat['nlink'] !== 1))) {
                throw new RuntimeException('HTTP_IMAGE_AUTHORITY_UNSAFE');
            }
        }
    }

    /** @return list<array{path:string,readonly:bool}> */
    private static function mounts(string $bytes): array
    {
        $result = [];
        foreach (explode("\n", trim($bytes)) as $line) {
            $parts = explode(' ', $line);
            if (count($parts) < 10 || !in_array('-', $parts, true)) {
                throw new RuntimeException('HTTP_MOUNT_TABLE_INVALID');
            }
            $path = preg_replace_callback('/\\\\([0-7]{3})/', static fn(array $match): string => chr(octdec($match[1])), $parts[4]);
            $result[] = ['path' => $path, 'readonly' => in_array('ro', explode(',', $parts[5]), true)];
        }
        return $result;
    }

    /** @param list<array{path:string,readonly:bool}> $mounts @return array{path:string,readonly:bool} */
    private static function effective(array $mounts, string $path): array
    {
        $effective = null;
        foreach ($mounts as $mount) {
            if (($mount['path'] === '/' || $path === $mount['path'] || str_starts_with($path, $mount['path'] . '/'))
                && ($effective === null || strlen($mount['path']) > strlen($effective['path']))) {
                $effective = $mount;
            }
        }
        if ($effective === null) {
            throw new RuntimeException('HTTP_MOUNT_TABLE_INVALID');
        }
        return $effective;
    }
}
