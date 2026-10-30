<?php

declare(strict_types=1);

namespace app\platform\services\plugin;

use app\platform\exception\plugin\PluginPackageException;
use app\common\module\PackageCheck;
use PeanutAdmin\Kernel\Module\StrictVersionConstraintMatcher;
use Throwable;

/** Read-only author preflight shared by the Module CLI and automation. */
final readonly class ModuleAuthorCheckHost
{
    private const CHECK_IDS = [
        'manifest',
        'version',
        'dependencies',
        'permissions',
        'menus',
        'frontend',
        'package',
    ];

    public function __construct(
        private string $projectRoot,
        private string $kernelVersion = '1.0.0',
    ) {}

    /**
     * @return array{
     *   status:string,code:string,reason:string,remediation:string,
     *   checks:list<array{id:string,status:string,code:string,reason:string,remediation:string}>,
     *   module:array{key:string,version:?string,kernel_version:string}
     * }
     */
    public function inspect(
        string $moduleKey,
        ?string $packagePath = null,
        ?string $expectedSha256 = null,
    ): array {
        $moduleKey = trim($moduleKey);
        $packagePath = $this->optional($packagePath);
        $expectedSha256 = $this->optional($expectedSha256);
        $checks = $this->initialChecks();
        $preflight = new PackageCheck($this->root());

        try {
            $inspection = $preflight->inspect($moduleKey);
            $checks['manifest'] = $this->passed(
                'manifest',
                'MODULE_CHECK_MANIFEST_READY',
                'Module manifest、Core schema 和 key 派生路径已通过检查',
            );
            $checks['permissions'] = $this->passed(
                'permissions',
                'MODULE_CHECK_PERMISSIONS_READY',
                '权限 key 均位于 Module 命名空间内',
            );
            $checks['menus'] = $this->passed(
                'menus',
                'MODULE_CHECK_MENUS_READY',
                '菜单权限引用均指向本 Module 已声明权限',
            );
            $checks['frontend'] = $this->passed(
                'frontend',
                'MODULE_CHECK_FRONTEND_READY',
                '前端入口与 Module key 派生路径一致且文件可读',
            );
        } catch (Throwable $exception) {
            $id = $this->sourceFailureCheckId($exception);
            $checks[$id] = $this->failure($id, $exception);
            return $this->result($moduleKey, null, $checks);
        }

        $matcher = new StrictVersionConstraintMatcher();
        try {
            if (!$matcher->matches($inspection['version'], $inspection['version'])) {
                throw new PluginPackageException(
                    'MODULE_CHECK_VERSION_INVALID',
                    'Module version is not a supported exact SemVer.',
                );
            }
            $constraint = (string) ($inspection['manifest']->data['kernel_constraint'] ?? '');
            if (!$matcher->matches($this->kernelVersion, $constraint)) {
                throw new PluginPackageException(
                    'MODULE_CHECK_KERNEL_INCOMPATIBLE',
                    'Module kernel_constraint does not accept the selected Kernel version.',
                );
            }
            $checks['version'] = $this->passed(
                'version',
                'MODULE_CHECK_VERSION_READY',
                'Module SemVer 和 Kernel 版本约束已通过严格匹配',
            );
        } catch (Throwable $exception) {
            $checks['version'] = $this->failure('version', $exception);
        }

        $availableVersions = [$inspection['key'] => $inspection['version']];
        try {
            $availableVersions = $this->dependencyVersions($preflight, $inspection);
            $checks['dependencies'] = $this->passed(
                'dependencies',
                'MODULE_CHECK_DEPENDENCIES_READY',
                '依赖来源、版本约束和依赖顺序已通过检查',
            );
        } catch (Throwable $exception) {
            $checks['dependencies'] = $this->failure('dependencies', $exception);
        }

        try {
            $this->checkPackage(
                $inspection['key'],
                $inspection['version'],
                $packagePath,
                $expectedSha256,
                $availableVersions,
            );
            $checks['package'] = $this->passed(
                'package',
                'MODULE_CHECK_PACKAGE_READY',
                '临时或指定 package 的 archive、inventory、manifest 和依赖已通过现有 archive validator',
            );
        } catch (Throwable $exception) {
            $checks['package'] = $this->failure('package', $exception);
        }

        return $this->result($moduleKey, $inspection['version'], $checks);
    }

    /**
     * @param array{
     *   key:string,version:string,backend_relative:string,frontend_relative:?string,
     *   frontend_contributions:list<array{client_key:string,entry:string,root:string}>,
     *   manifest:\PeanutAdmin\Kernel\Module\ManifestDocument,
     *   dependencies:list<array{module_key:string,version:string}>,owned_tables:list<string>
     * } $rootInspection
     * @return array<string,string>
     */
    private function dependencyVersions(PackageCheck $preflight, array $rootInspection): array
    {
        $modules = [];
        $available = [];
        $pending = [$rootInspection];
        while ($pending !== []) {
            $inspection = array_pop($pending);
            $key = $inspection['key'];
            if (isset($modules[$key])) {
                continue;
            }
            $seenDependencies = [];
            foreach ($inspection['dependencies'] as $dependency) {
                $dependencyKey = $dependency['module_key'];
                if (isset($seenDependencies[$dependencyKey])) {
                    throw new PluginPackageException(
                        'MODULE_CHECK_DEPENDENCY_DUPLICATE',
                        'Module declares one dependency more than once.',
                    );
                }
                $seenDependencies[$dependencyKey] = true;
                if (!isset($available[$dependencyKey])) {
                    try {
                        $dependencyInspection = $preflight->inspect($dependencyKey);
                    } catch (Throwable $exception) {
                        throw new PluginPackageException(
                            'MODULE_CHECK_DEPENDENCY_SOURCE_INVALID',
                            'A declared Module dependency is missing or invalid in the source tree.',
                            0,
                            $exception,
                        );
                    }
                    $available[$dependencyKey] = $dependencyInspection['version'];
                    $pending[] = $dependencyInspection;
                }
            }
            $available[$key] = $inspection['version'];
            $modules[$key] = [
                'version' => $inspection['version'],
                'dependencies' => $inspection['dependencies'],
            ];
        }
        $preflight->dependencyOrder($modules, $available);
        ksort($available, SORT_STRING);
        return $available;
    }

    /** @param array<string,string> $availableVersions */
    private function checkPackage(
        string $moduleKey,
        string $moduleVersion,
        ?string $packagePath,
        ?string $expectedSha256,
        array $availableVersions,
    ): void {
        if ($packagePath === null && $expectedSha256 !== null) {
            throw new PluginPackageException(
                'MODULE_CHECK_PACKAGE_PATH_REQUIRED',
                'Package sha256 cannot be checked without a package path.',
            );
        }
        $archiveService = new PluginPackageArchiveService($this->root() . '/server');
        $temporaryArchive = null;
        $package = null;
        try {
            if ($packagePath === null) {
                $temporary = tempnam(sys_get_temp_dir(), 'peanut-module-check-');
                if (!is_string($temporary) || !unlink($temporary)) {
                    throw new PluginPackageException(
                        'MODULE_CHECK_PACKAGE_TEMPORARY_UNAVAILABLE',
                        'Temporary package path cannot be allocated.',
                    );
                }
                $archive = $temporary . '.tar';
                $temporaryArchive = $archive;
                $packed = $archiveService->packModule($moduleKey, $archive);
                $digest = $packed['sha256'];
            } else {
                $archive = str_starts_with($packagePath, DIRECTORY_SEPARATOR)
                    ? $packagePath
                    : $this->root() . '/' . ltrim($packagePath, '/');
                if (!is_file($archive) || !is_readable($archive)) {
                    throw new PluginPackageException(
                        'MODULE_CHECK_PACKAGE_UNREADABLE',
                        'Package archive is missing or unreadable.',
                    );
                }
                $digest = $expectedSha256 ?? hash_file('sha256', $archive);
                if (!is_string($digest)) {
                    throw new PluginPackageException(
                        'MODULE_CHECK_PACKAGE_UNREADABLE',
                        'Package archive digest cannot be read.',
                    );
                }
            }
            $package = $archiveService->verify($archive, $digest, [], null, $availableVersions);
            $module = $package->modules[$moduleKey] ?? null;
            if ($package->packageKey !== $moduleKey || count($package->modules) !== 1
                || !is_array($module) || ($module['version'] ?? null) !== $moduleVersion) {
                throw new PluginPackageException(
                    'MODULE_CHECK_PACKAGE_IDENTITY_MISMATCH',
                    'Package identity differs from the checked source Module.',
                );
            }
        } finally {
            if ($package !== null) {
                $archiveService->cleanup($package);
            }
            if ($temporaryArchive !== null && is_file($temporaryArchive)) {
                unlink($temporaryArchive);
            }
        }
    }

    /** @return array<string,array{id:string,status:string,code:string,reason:string,remediation:string}> */
    private function initialChecks(): array
    {
        $checks = [];
        foreach (self::CHECK_IDS as $id) {
            $checks[$id] = $this->skipped(
                $id,
                'MODULE_CHECK_NOT_RUN',
                '前置检查未完成，本项未运行',
                '先修复前置失败项后重新运行 module:check',
            );
        }
        return $checks;
    }

    private function sourceFailureCheckId(Throwable $exception): string
    {
        $code = $exception instanceof PluginPackageException ? $exception->errorCode : '';
        if (str_contains($code, 'FRONTEND')) {
            return 'frontend';
        }
        if ($code === 'MODULE_PACKAGE_PERMISSION_NOT_NAMESPACED') {
            return 'permissions';
        }
        if ($code === 'MODULE_PACKAGE_PERMISSION_REFERENCE_INVALID') {
            return 'menus';
        }
        if (str_contains($code, 'DEPENDENCY')) {
            return 'dependencies';
        }
        return 'manifest';
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function failure(string $id, Throwable $exception): array
    {
        $code = $exception instanceof PluginPackageException
            ? $exception->errorCode
            : 'MODULE_CHECK_UNEXPECTED_FAILURE';
        return $this->failed($id, $code, $exception->getMessage(), $this->remediation($id, $code));
    }

    private function remediation(string $id, string $code): string
    {
        return match ($code) {
            'MODULE_PACKAGE_PERMISSION_NOT_NAMESPACED' => '为每个权限 key 添加当前 Module key 前缀后重试',
            'MODULE_PACKAGE_PERMISSION_REFERENCE_INVALID' => '让菜单只引用当前 Module 已声明的 namespaced 权限',
            'MODULE_PACKAGE_FRONTEND_ENTRY_MISMATCH',
            'MODULE_PACKAGE_FRONTEND_ENTRY_MISSING' => '按 frontend client 与 Module key 派生各端 modules/<slug>/contribution.ts，并补齐对应 package.json',
            'MODULE_CHECK_VERSION_INVALID' => '把 version 改为严格 SemVer，例如 1.0.0',
            'MODULE_CHECK_KERNEL_INCOMPATIBLE' => '修正 kernel_constraint，或显式选择兼容的 Kernel 版本重新检查',
            'MODULE_PACKAGE_DEPENDENCY_MISSING',
            'MODULE_PACKAGE_DEPENDENCY_INCOMPATIBLE',
            'MODULE_PACKAGE_DEPENDENCY_CYCLE',
            'MODULE_CHECK_DEPENDENCY_DUPLICATE',
            'MODULE_CHECK_DEPENDENCY_SOURCE_INVALID' => '修正 dependencies 的 Module key、版本约束和依赖图后重试',
            'MODULE_CHECK_PACKAGE_PATH_REQUIRED',
            'MODULE_CHECK_PACKAGE_UNREADABLE',
            'MODULE_CHECK_PACKAGE_TEMPORARY_UNAVAILABLE' => '确认临时目录可写，或提供可读 package 路径及对应 SHA-256',
            'MODULE_CHECK_PACKAGE_IDENTITY_MISMATCH' => '使用 module:pack 为当前 Module 重新生成单 Module package',
            default => match ($id) {
                'manifest' => '按 Core module manifest schema 修正 module.json 和 key 派生路径后重试',
                'package' => '使用 module:pack 重新生成 package，并核对 archive SHA-256 后重试',
                default => '修复本项报告的问题后重新运行 module:check',
            },
        };
    }

    /**
     * @param array<string,array{id:string,status:string,code:string,reason:string,remediation:string}> $checks
     * @return array{
     *   status:string,code:string,reason:string,remediation:string,
     *   checks:list<array{id:string,status:string,code:string,reason:string,remediation:string}>,
     *   module:array{key:string,version:?string,kernel_version:string}
     * }
     */
    private function result(string $moduleKey, ?string $version, array $checks): array
    {
        $checks = array_values($checks);
        $failed = array_values(array_filter(
            $checks,
            static fn(array $check): bool => $check['status'] === 'failed',
        ));
        if ($failed !== []) {
            return [
                'status' => 'blocked',
                'code' => 'MODULE_CHECK_BLOCKED',
                'reason' => sprintf('Module 作者检查有 %d 项未通过', count($failed)),
                'remediation' => '按 checks 中的修复建议处理后重新运行 module:check',
                'checks' => $checks,
                'module' => ['key' => $moduleKey, 'version' => $version, 'kernel_version' => $this->kernelVersion],
            ];
        }
        return [
            'status' => 'ready',
            'code' => 'MODULE_CHECK_READY',
            'reason' => 'Module 作者只读检查已通过',
            'remediation' => '可以继续聚焦验证，或使用 module:pack 生成确定性 package',
            'checks' => $checks,
            'module' => ['key' => $moduleKey, 'version' => $version, 'kernel_version' => $this->kernelVersion],
        ];
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function passed(string $id, string $code, string $reason): array
    {
        return ['id' => $id, 'status' => 'passed', 'code' => $code, 'reason' => $reason, 'remediation' => ''];
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function failed(string $id, string $code, string $reason, string $remediation): array
    {
        return ['id' => $id, 'status' => 'failed', 'code' => $code, 'reason' => $reason, 'remediation' => $remediation];
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function skipped(string $id, string $code, string $reason, string $remediation): array
    {
        return ['id' => $id, 'status' => 'skipped', 'code' => $code, 'reason' => $reason, 'remediation' => $remediation];
    }

    private function root(): string
    {
        return rtrim($this->projectRoot, '/');
    }

    private function optional(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
