<?php

declare(strict_types=1);

namespace app\common\infrastructure\module;

use app\common\exception\module\ModuleScaffoldException;
use app\common\value\module\ModulePhpNamespace;
use app\common\module\PackageCheck;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleKey;

/** Generates the one key-derived backend/frontend Module skeleton. */
final class ModuleScaffoldGenerator
{
    private const BACKEND_FILES = [
        'module.json' => 'backend/module.json.stub',
        'src/ModuleProvider.php' => 'backend/ModuleProvider.php.stub',
        'route/app.php' => 'backend/route/app.php.stub',
        'resources/permissions.json' => 'backend/empty-array.json.stub',
        'resources/menus.json' => 'backend/empty-array.json.stub',
        'resources/setting-definitions.json' => 'backend/empty-array.json.stub',
        'composer.json' => 'backend/composer.json.stub',
    ];

    private const FRONTEND_FILES = [
        'contribution.ts' => 'frontend/contribution.ts.stub',
        'package.json' => 'frontend/package.json.stub',
    ];

    private const TEST_FILES = [
        'ModuleStructureTest.php' => 'tests/ModuleStructureTest.php.stub',
        'SECURITY.md' => 'tests/SECURITY.md.stub',
    ];

    private ModuleHostLayout $layout;
    private string $templateRoot;

    public function __construct(
        private string $projectRoot,
        ?string $templateRoot = null,
        private ?string $composerBinary = null,
    ) {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new ModuleScaffoldException('MODULE_CREATE_PROJECT_UNAVAILABLE', 'Module project root is unavailable.');
        }
        $this->projectRoot = $root;
        $templateRoot ??= $root . '/server/resources/module-scaffold';
        $resolvedTemplates = realpath($templateRoot);
        if ($resolvedTemplates === false || !is_dir($resolvedTemplates)) {
            throw new ModuleScaffoldException('MODULE_CREATE_TEMPLATE_INVALID', 'Module scaffold template is unavailable.');
        }
        $this->templateRoot = $resolvedTemplates;
        $this->layout = ModuleHostLayoutFactory::pathLayout();
    }

    /**
     * @return array{
     *   operation:string,module_key:string,vendor:string,backend_path:string,
     *   frontend_path:?string,test_path:string,frontend_entry:?string,php_package:string,web_package:?string
     * }
     */
    public function create(string $moduleKey, ?string $vendor = null, string $client = 'none'): array
    {
        if (!in_array($client, ['none', 'admin-web'], true)) {
            throw new ModuleScaffoldException('MODULE_CREATE_CLIENT_INVALID', 'Select none or admin-web explicitly.');
        }
        $withFrontend = $client === 'admin-web';
        $moduleKey = trim($moduleKey);
        try {
            $key = ModuleKey::fromString($moduleKey);
        } catch (\InvalidArgumentException $exception) {
            throw new ModuleScaffoldException('MODULE_CREATE_KEY_INVALID', 'Module key is invalid.', 0, $exception);
        }

        $pascalSegments = $key->pascalSegments();
        $rawSegments = explode('.', $key->value());
        if (count($rawSegments) !== 2) {
            throw new ModuleScaffoldException('MODULE_CREATE_KEY_INVALID', 'Module key must contain vendor and name.');
        }
        $derivedVendor = $rawSegments[0];
        $vendor = trim((string) $vendor);
        if ($vendor !== '' && preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $vendor) !== 1) {
            throw new ModuleScaffoldException('MODULE_CREATE_VENDOR_INVALID', 'Module vendor is invalid.');
        }
        if ($vendor !== '' && $vendor !== $derivedVendor) {
            throw new ModuleScaffoldException(
                'MODULE_CREATE_VENDOR_MISMATCH',
                'Module vendor must equal the namespace derived from the Module key.',
            );
        }
        $vendor = $derivedVendor;

        $backendRelative = rtrim($this->layout->backendRelativePath($key), '/');
        $frontendRelative = rtrim($this->layout->frontendRelativePath($key), '/');
        $frontendEntry = $frontendRelative . '/contribution.ts';
        $backendRoot = $this->projectRoot . '/' . $backendRelative;
        $frontendRoot = $this->projectRoot . '/' . $frontendRelative;
        $testRelative = 'server/tests/Modules/' . implode('/', $pascalSegments);
        $testRoot = $this->projectRoot . '/' . $testRelative;
        $backendBase = $this->projectRoot . '/server/app/modules';
        $frontendBase = $this->projectRoot . '/web/src/modules';
        $testBase = $this->projectRoot . '/server/tests';
        $backendCleanupBase = $this->assertAvailableTarget($backendBase, $backendRoot);
        $frontendCleanupBase = $frontendBase;
        if ($withFrontend) {
            $frontendCleanupBase = $this->assertAvailableTarget($frontendBase, $frontendRoot);
        }
        $testCleanupBase = $this->assertAvailableTarget($testBase, $testRoot);

        $vendorKey = $rawSegments[0];
        $slug = $key->slug();
        try {
            $namespace = rtrim(ModulePhpNamespace::generated(
                $vendor,
                $pascalSegments[array_key_last($pascalSegments)],
            ), '\\');
        } catch (\InvalidArgumentException $exception) {
            throw new ModuleScaffoldException('MODULE_CREATE_NAMESPACE_INVALID', 'Module PHP namespace cannot be derived.', 0, $exception);
        }
        $this->assertNamespaceAvailable($moduleKey, $namespace . '\\');
        $displayName = implode(' ', array_map(
            static fn(string $segment): string => implode(' ', array_map('ucfirst', explode('-', $segment))),
            $rawSegments,
        ));
        $description = "Generated {$moduleKey} Module skeleton.";
        $composerVendor = $vendorKey === 'peanut' ? 'peanut-business' : $vendorKey;
        $npmScope = $vendorKey === 'peanut' ? 'peanut-admin' : $vendorKey;
        $phpPackage = $composerVendor . '/' . $slug;
        $webPackage = '@' . $npmScope . '/' . $slug;
        $tableName = 'pa_' . str_replace('-', '_', $slug) . '_record';
        $replacements = [
            '${MODULE_KEY}' => $moduleKey,
            '${VENDOR}' => $vendor,
            '${MODULE}' => $pascalSegments[array_key_last($pascalSegments)],
            '${FRONTEND_SLUG}' => $slug,
            '${FRONTEND_ENTRY}' => $frontendEntry,
            '${PHP_NAMESPACE}' => $namespace,
            '${MODULE_KEY_JSON}' => $this->jsonString($moduleKey),
            '${NAME_JSON}' => $this->jsonString($displayName),
            '${DESCRIPTION_JSON}' => $this->jsonString($description),
            '${PHP_PACKAGE_JSON}' => $this->jsonString($phpPackage),
            '${WEB_PACKAGE_FIELD}' => $withFrontend ? '"web_package": ' . $this->jsonString($webPackage) . ',' : '',
            '${WEB_PACKAGE_JSON}' => $this->jsonString($webPackage),
            '${FRONTEND_DECLARATION_JSON}' => $withFrontend ? json_encode(['entry' => $frontendEntry], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : '{}',
            '${BACKEND_RELATIVE_JSON}' => $this->jsonString($backendRelative),
            '${PHP_PREFIX_JSON}' => $this->jsonString($namespace . '\\'),
            '${FRONTEND_TEST_JSON}' => $withFrontend ? $this->jsonString($frontendEntry) : 'null',
            '${BACKEND_PROVIDER_JSON}' => $this->jsonString($namespace . '\\ModuleProvider'),
            '${FRONTEND_ENTRY_JSON}' => $this->jsonString($frontendEntry),
            '${AUTOLOAD_NAMESPACE_JSON}' => $this->jsonString($namespace . '\\'),
            '${TABLE_NAME}' => $tableName,
        ];

        $backendCreated = false;
        $frontendCreated = false;
        $testCreated = false;
        try {
            if (!mkdir($backendRoot, 0755, true)) {
                throw new ModuleScaffoldException('MODULE_CREATE_WRITE_FAILED', 'Module backend root cannot be created.');
            }
            $backendCreated = true;
            if ($withFrontend) {
                if (!mkdir($frontendRoot, 0755, true)) {
                    throw new ModuleScaffoldException('MODULE_CREATE_WRITE_FAILED', 'Module frontend root cannot be created.');
                }
                $frontendCreated = true;
            }
            if (!mkdir($testRoot, 0755, true)) {
                throw new ModuleScaffoldException('MODULE_CREATE_WRITE_FAILED', 'Module test root cannot be created.');
            }
            $testCreated = true;
            $this->writeFiles($backendRoot, self::BACKEND_FILES, $replacements);
            if ($withFrontend) {
                $this->writeFiles($frontendRoot, self::FRONTEND_FILES, $replacements);
            }
            $this->writeFiles($testRoot, self::TEST_FILES, $replacements);
            $this->postflight($moduleKey, $backendRoot, $withFrontend);
        } catch (ModuleScaffoldException $exception) {
            if ($testCreated) {
                $this->removeCreatedTree($testRoot, $testCleanupBase);
            }
            if ($frontendCreated) {
                $this->removeCreatedTree($frontendRoot, $frontendCleanupBase);
            }
            if ($backendCreated) {
                $this->removeCreatedTree($backendRoot, $backendCleanupBase);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($testCreated) {
                $this->removeCreatedTree($testRoot, $testCleanupBase);
            }
            if ($frontendCreated) {
                $this->removeCreatedTree($frontendRoot, $frontendCleanupBase);
            }
            if ($backendCreated) {
                $this->removeCreatedTree($backendRoot, $backendCleanupBase);
            }
            throw new ModuleScaffoldException('MODULE_CREATE_FAILED', 'Module scaffold generation failed.', 0, $exception);
        }

        return [
            'operation' => 'created',
            'module_key' => $moduleKey,
            'vendor' => $vendor,
            'backend_path' => $backendRelative,
            'frontend_path' => $withFrontend ? $frontendRelative : null,
            'test_path' => $testRelative,
            'frontend_entry' => $withFrontend ? $frontendEntry : null,
            'php_package' => $phpPackage,
            'web_package' => $withFrontend ? $webPackage : null,
        ];
    }

    /** Returns the nearest existing ancestor: rollback must preserve it even when empty. */
    private function assertAvailableTarget(string $base, string $target): string
    {
        $resolvedBase = realpath($base);
        if ($resolvedBase === false || $resolvedBase !== $base || !str_starts_with($target, $base . '/')) {
            throw new ModuleScaffoldException('MODULE_CREATE_PROJECT_UNAVAILABLE', 'Module source root is unavailable.');
        }
        $relative = substr(dirname($target), strlen($base) + 1);
        $cursor = $base;
        $cleanupBase = $base;
        foreach (array_filter(explode('/', $relative), 'strlen') as $segment) {
            $cursor .= '/' . $segment;
            if (is_link($cursor) || (file_exists($cursor) && !is_dir($cursor))) {
                throw new ModuleScaffoldException('MODULE_CREATE_PATH_INVALID', 'Module target path contains an unsafe ancestor.');
            }
            if (is_dir($cursor)) {
                $cleanupBase = $cursor;
            }
        }
        if (file_exists($target) || is_link($target)) {
            throw new ModuleScaffoldException('MODULE_CREATE_TARGET_EXISTS', 'Module target already exists.');
        }
        return $cleanupBase;
    }

    private function assertNamespaceAvailable(string $moduleKey, string $namespace): void
    {
        $roots = [];
        foreach (glob($this->projectRoot . '/server/app/modules/*/*/composer.json') ?: [] as $composerPath) {
            try {
                $moduleRoot = dirname($composerPath);
                $manifest = json_decode(
                    (string) file_get_contents($moduleRoot . '/module.json'),
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
                $existingKey = is_array($manifest) ? ($manifest['key'] ?? null) : null;
                if (!is_string($existingKey) || isset($roots[$existingKey])) {
                    throw new \InvalidArgumentException('Existing Module identity is invalid.');
                }
                $roots[$existingKey] = $moduleRoot;
            } catch (\InvalidArgumentException|\JsonException $exception) {
                throw new ModuleScaffoldException(
                    'MODULE_CREATE_NAMESPACE_INVALID',
                    'An existing Module PHP namespace declaration is invalid.',
                    0,
                    $exception,
                );
            }
        }
        try {
            ModuleHostLayoutFactory::assertNamespaceAvailable($moduleKey, $namespace, $roots);
        } catch (\InvalidArgumentException $exception) {
            throw new ModuleScaffoldException(
                'MODULE_CREATE_NAMESPACE_CONFLICT',
                'Module PHP namespace overlaps an existing Module, host, or Core prefix.',
                0,
                $exception,
            );
        }
    }

    /** @param array<string,?string> $files @param array<string,string> $replacements */
    private function writeFiles(string $targetRoot, array $files, array $replacements): void
    {
        foreach ($files as $relative => $template) {
            $relative = strtr($relative, $replacements);
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')
                || preg_match('/\$\{[A-Z][A-Z0-9_]*\}/', $relative) === 1) {
                throw new ModuleScaffoldException('MODULE_CREATE_TEMPLATE_INVALID', 'Module scaffold target path is invalid.');
            }
            $path = $targetRoot . '/' . $relative;
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                throw new ModuleScaffoldException('MODULE_CREATE_WRITE_FAILED', 'Module scaffold directory cannot be created.');
            }
            $contents = '';
            if ($template !== null) {
                $templatePath = $this->templateRoot . '/' . $template;
                if (!is_file($templatePath) || is_link($templatePath)) {
                    throw new ModuleScaffoldException('MODULE_CREATE_TEMPLATE_INVALID', 'Module scaffold template is invalid.');
                }
                $templateContents = file_get_contents($templatePath);
                if (!is_string($templateContents)) {
                    throw new ModuleScaffoldException('MODULE_CREATE_TEMPLATE_INVALID', 'Module scaffold template cannot be read.');
                }
                $contents = strtr($templateContents, $replacements);
                if (preg_match('/\$\{[A-Z][A-Z0-9_]*\}/', $contents) === 1) {
                    throw new ModuleScaffoldException('MODULE_CREATE_TEMPLATE_INVALID', 'Module scaffold template has an unknown variable.');
                }
            }
            $written = file_put_contents($path, $contents, LOCK_EX);
            if ($written === false || $written !== strlen($contents)) {
                throw new ModuleScaffoldException('MODULE_CREATE_WRITE_FAILED', 'Module scaffold file cannot be written.');
            }
        }
    }

    private function postflight(string $moduleKey, string $backendRoot, bool $withFrontend): void
    {
        $segments = ModuleKey::fromString($moduleKey)->pascalSegments();
        // 生成与验收使用同一份文件清单；没有消费者的类分层不预建空目录。
        foreach (array_keys(self::BACKEND_FILES) as $relative) {
            $relative = strtr($relative, ['${MODULE}' => $segments[array_key_last($segments)]]);
            if (!is_file($backendRoot . '/' . $relative)) {
                throw new ModuleScaffoldException('MODULE_CREATE_POSTCHECK_FAILED', 'Generated Module backend is incomplete.');
            }
        }
        try {
            $inspection = (new PackageCheck($this->projectRoot))->inspect($moduleKey);
        } catch (\Throwable $exception) {
            throw new ModuleScaffoldException('MODULE_CREATE_POSTCHECK_FAILED', 'Generated Module manifest preflight failed.', 0, $exception);
        }
        if ($inspection['backend_relative'] !== rtrim($this->layout->backendRelativePath(ModuleKey::fromString($moduleKey)), '/')
            || $inspection['frontend_relative'] !== ($withFrontend ? rtrim($this->layout->frontendRelativePath(ModuleKey::fromString($moduleKey)), '/') : null)) {
            throw new ModuleScaffoldException('MODULE_CREATE_POSTCHECK_FAILED', 'Generated Module path differs from its key.');
        }
        $this->validateComposer($backendRoot);
    }

    private function validateComposer(string $backendRoot): void
    {
        $binary = trim($this->composerBinary ?? ($this->projectRoot . '/scripts/project-composer'));
        if ($binary === '' || str_contains($binary, "\0")) {
            throw new ModuleScaffoldException('MODULE_CREATE_COMPOSER_INVALID', 'Composer validator is unavailable.');
        }
        $pipes = [];
        $process = @proc_open(
            [$binary, 'validate', '--no-check-publish', '--no-interaction', '--working-dir=' . $backendRoot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new ModuleScaffoldException('MODULE_CREATE_COMPOSER_INVALID', 'Composer validator is unavailable.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new ModuleScaffoldException(
                'MODULE_CREATE_COMPOSER_INVALID',
                'Generated Composer manifest is invalid: ' . trim((string) $stdout . "\n" . (string) $stderr),
            );
        }
    }

    private function jsonString(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function removeCreatedTree(string $path, string $base): void
    {
        if (is_dir($path) && !is_link($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && !$entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }
            rmdir($path);
        }
        $cursor = dirname($path);
        while ($cursor !== $base && str_starts_with($cursor, $base . '/')) {
            if (!is_dir($cursor) || is_link($cursor) || (scandir($cursor) ?: []) !== ['.', '..']) {
                break;
            }
            rmdir($cursor);
            $cursor = dirname($cursor);
        }
    }
}
