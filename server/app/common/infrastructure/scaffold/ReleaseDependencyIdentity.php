<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use RuntimeException;

/**
 * V3 依赖身份值校验，供源码构建、安装和可信升级入口共同使用。
 * 不下载、不执行包、不推断发布或安装成功；原生锁安装另行验证渠道与制品。
 */
final class ReleaseDependencyIdentity
{
    public const WEB_PACKAGES = [
        '@peanut-admin/client', '@peanut-admin/vue', '@peanut-admin/ui-vue',
        '@peanut-admin/nuxt', '@peanut-admin/uniapp', '@peanut-admin/testing',
    ];
    private const SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    /** @return 'archive'|'registry'|'local-archive' */
    public static function validate(mixed $php, mixed $web, string $phpError, string $webError): string
    {
        $phpLocal = is_array($php)
            && array_keys($php) === ['package', 'constraint', 'resolved_version', 'source_type', 'source_identity']
            && $php['source_type'] === 'local' && self::localIdentity($php['source_identity'] ?? null);
        $phpGit = is_array($php)
            && array_keys($php) === ['package', 'constraint', 'resolved_version', 'source_type', 'source_url', 'source_reference']
            && $php['source_type'] === 'git' && self::https($php['source_url'])
            && is_string($php['source_reference']) && preg_match('/^[0-9a-f]{40}$/D', $php['source_reference']) === 1;
        if (!is_array($php) || $php['package'] !== 'peanut-admin/core'
            || !is_string($php['constraint']) || !is_string($php['resolved_version'])
            || (!$phpLocal && !$phpGit)) {
            throw new RuntimeException($phpError);
        }
        $development = preg_match('/^dev-[A-Za-z0-9._-]+$/D', $php['constraint']) === 1
            && $php['constraint'] === $php['resolved_version'];
        $fixed = self::fixed($php['constraint'])
            && $php['constraint'] === (str_starts_with($php['resolved_version'], 'v')
                ? substr($php['resolved_version'], 1) : $php['resolved_version']);
        if (!$development && !$fixed && !($phpLocal && self::fixed($php['resolved_version']) && $php['constraint'] === $php['resolved_version'])) {
            throw new RuntimeException($phpError);
        }

        $webLocal = is_array($web)
            && array_keys($web) === ['source_type', 'source_identity', 'packages']
            && $web['source_type'] === 'local' && self::localIdentity($web['source_identity'] ?? null);
        $webGit = is_array($web)
            && array_keys($web) === ['source_type', 'source_url', 'source_reference', 'packages']
            && $web['source_type'] === 'git' && self::https($web['source_url'])
            && is_string($web['source_reference']) && preg_match('/^[0-9a-f]{40}$/D', $web['source_reference']) === 1;
        if (!is_array($web) || (!$webLocal && !$webGit)
            || !is_array($web['packages']) || array_keys($web['packages']) !== self::WEB_PACKAGES) {
            throw new RuntimeException($webError);
        }
        $mode = null;
        $webVersions = [];
        foreach ($web['packages'] as $packageName => $identity) {
            if (!is_array($identity) || !is_string($identity['version'] ?? null)
                || preg_match(self::SEMVER, $identity['version']) !== 1) {
                throw new RuntimeException($webError);
            }
            if (array_keys($identity) === ['version', 'archive', 'sha256']) {
                $current = 'archive';
                $expectedArchive = 'packages/core-web/peanut-admin-'
                    . substr((string) $packageName, strlen('@peanut-admin/')) . '-' . $identity['version'] . '.tgz';
                if (!is_string($identity['archive'])
                    || $identity['archive'] !== $expectedArchive
                    || !is_string($identity['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $identity['sha256']) !== 1) {
                    throw new RuntimeException($webError);
                }
            } elseif (array_keys($identity) === ['version', 'resolved', 'integrity']) {
                $current = 'registry';
                if (!self::fixed($identity['version']) || !self::https($identity['resolved'])
                    || !self::integrity($identity['integrity'])) {
                    throw new RuntimeException($webError);
                }
            } else {
                throw new RuntimeException($webError);
            }
            if ($mode !== null && $mode !== $current) {
                throw new RuntimeException($webError);
            }
            $mode = $current;
            if ($webLocal && $current !== 'archive') {
                throw new RuntimeException($webError);
            }
            $webVersions[$identity['version']] = true;
        }
        if ($phpLocal !== $webLocal || ($webLocal && count($webVersions) !== 1)) {
            throw new RuntimeException($webError);
        }
        // Independently pinned PHP source and Web registry artifacts are valid development inputs.
        // Published packaging separately requires an exact released PHP version; follow the template release contract.
        return $phpLocal ? 'local-archive' : $mode;
    }

    /** Match a Composer package against an already validated PHP Core declaration. */
    public static function phpComposerReference(array $package, array $php, string $error): string
    {
        if (($package['name'] ?? null) !== 'peanut-admin/core'
            || ($package['version'] ?? null) !== $php['resolved_version']) {
            throw new RuntimeException($error);
        }
        if ($php['source_type'] === 'local') {
            $reference = $php['source_identity']['snapshot_sha256'];
            if (isset($package['source']) || ($package['dist']['type'] ?? null) !== 'zip'
                || ($package['dist']['url'] ?? null) !== '../packages/core-php/peanut-admin-core-' . $php['resolved_version'] . '.zip'
                || ($package['dist']['reference'] ?? null) !== $reference
                || !is_string($package['dist']['shasum'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/D', $package['dist']['shasum']) !== 1) {
                throw new RuntimeException($error);
            }
            return $reference;
        }
        if (($package['source']['type'] ?? null) !== 'git'
            || ($package['source']['url'] ?? null) !== $php['source_url']
            || ($package['source']['reference'] ?? null) !== $php['source_reference']
            || ($package['dist']['reference'] ?? null) !== $php['source_reference']) {
            throw new RuntimeException($error);
        }
        return $php['source_reference'];
    }

    public static function verifyPhpArchiveBytes(array $package, string $bytes, string $error): void
    {
        $digest = $package['dist']['shasum'] ?? null;
        if (!is_string($digest) || preg_match('/^[a-f0-9]{40}$/D', $digest) !== 1
            || !hash_equals($digest, sha1($bytes))) {
            throw new RuntimeException($error);
        }
    }

    /** 旧本地归档身份仍须验证实际文件；原生 registry 身份由原生锁安装校验制品。 */
    public static function verifyArchives(string $root, array $web, string $error): void
    {
        foreach ($web['packages'] as $identity) {
            if (array_keys($identity) === ['version', 'resolved', 'integrity']) {
                continue;
            }
            $relative = $identity['archive'] ?? null;
            if (!is_string($relative)
                || preg_match('#^packages/core-web/peanut-admin-[a-z-]+-[0-9A-Za-z.+-]+\.tgz$#D', $relative) !== 1) {
                throw new RuntimeException($error);
            }
            $path = rtrim($root, '/');
            foreach (explode('/', $relative) as $segment) {
                $path .= '/' . $segment;
                if (is_link($path)) {
                    throw new RuntimeException($error);
                }
            }
            $digest = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($digest) || !is_string($identity['sha256'] ?? null)
                || !hash_equals($identity['sha256'], $digest)) {
                throw new RuntimeException($error);
            }
        }
    }

    public static function fixed(mixed $version): bool
    {
        return is_string($version) && preg_match(self::SEMVER, $version) === 1
            && preg_match('/(?:^|[.-])dev(?:[.+-]|$)/i', $version) !== 1;
    }

    private static function localIdentity(mixed $identity): bool
    {
        return is_array($identity) && array_keys($identity) === ['kind', 'snapshot_sha256']
            && ($identity['kind'] ?? null) === 'local'
            && is_string($identity['snapshot_sha256'] ?? null)
            && preg_match('/^[0-9a-f]{64}$/D', $identity['snapshot_sha256']) === 1;
    }

    private static function https(mixed $url): bool
    {
        if (!is_string($url) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? '') !== '' && ($parts['path'] ?? '') !== ''
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']);
    }

    private static function integrity(mixed $value): bool
    {
        if (!is_string($value) || !str_starts_with($value, 'sha512-')) {
            return false;
        }
        $encoded = substr($value, 7);
        $bytes = base64_decode($encoded, true);
        return is_string($bytes) && strlen($bytes) === 64 && base64_encode($bytes) === $encoded;
    }
}
