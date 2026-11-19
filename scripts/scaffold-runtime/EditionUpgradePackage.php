<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use app\common\validation\scaffold\ScaffoldPathGuard;
use app\common\value\scaffold\ScaffoldManifest;
use app\common\value\scaffold\ScaffoldSourceIdentity;
use RuntimeException;

final class EditionUpgradePackage
{
    private const VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    /** @return array{from_manifest:string,to_manifest:string,package:array<string,mixed>} */
    public function prepare(string $projectRoot, string $packageRoot, ?string $modeManifestPath = null): array
    {
        $prepared = $this->authenticate($projectRoot, $packageRoot, true);
        $prepared['from_manifest'] = ScaffoldManifest::writeRecordedBaseline($prepared['project_root'], $modeManifestPath);
        unset($prepared['project_root'], $prepared['application']);
        return $prepared;
    }

    /** Reauthenticate a plan-bound package after its target application manifest may already be active. */
    public function reauthenticate(string $projectRoot, string $packageRoot): array
    {
        $prepared = $this->authenticate($projectRoot, $packageRoot, false);
        unset($prepared['project_root'], $prepared['application']);
        return $prepared;
    }

    /** Authenticate the fixed template cache through its existing source manifest. */
    public function authenticateTemplate(string $projectRoot, string $packageRoot): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $package = ScaffoldPathGuard::projectRoot($packageRoot);
        $metadataPath = ScaffoldPathGuard::existingFileWithin($root, $package . '/template-package.json', 'TEMPLATE_UPGRADE_METADATA_UNSAFE');
        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        if ($package !== $root . '/.peanut/upgrades/template-packages/' . ($metadata['archive']['sha256'] ?? '')) {
            throw new RuntimeException('TEMPLATE_UPGRADE_CACHE_IDENTITY_INVALID');
        }
        $application = $this->applicationManifest($root);
        $target = ScaffoldManifest::load($package . '/scaffold-manifest.json');
        if (($metadata['protocol'] ?? null) !== 'peanut.template-package.v1'
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($metadata['archive']['sha256'] ?? '')) !== 1
            || ($metadata['scaffold_manifest_sha256'] ?? null) !== substr($target->digest(), 7)
            || ($metadata['version'] ?? null) !== $target->version()
            || ScaffoldSourceIdentity::from($metadata['source'] ?? [], true) !== $target->sourceIdentity()
            || ($metadata['source']['inventory_sha256'] ?? null) !== $target->release()['inventory_sha256']) {
            throw new RuntimeException('TEMPLATE_UPGRADE_SOURCE_IDENTITY_MISMATCH');
        }
        foreach ($target->files() as $file) {
            if (hash_file('sha256', $target->artifactPath($file)) !== $file['template_sha256']) {
                throw new RuntimeException('SCAFFOLD_ARTIFACT_DIGEST_MISMATCH: ' . $file['path']);
            }
        }
        $currentVersion = $application['template']['version'] ?? '';
        if (preg_match(self::VERSION, $currentVersion) !== 1 || preg_match(self::VERSION, $target->version()) !== 1
            || Semver::compare($currentVersion, $target->version()) > 0) {
            throw new RuntimeException('TEMPLATE_UPGRADE_SOURCE_RANGE_UNSUPPORTED');
        }
        if (($metadata['edition'] ?? null) !== ($application['application']['edition'] ?? null)
            || ($target->data['edition']['name'] ?? null) !== ($application['edition']['name'] ?? null)) {
            throw new RuntimeException('TEMPLATE_UPGRADE_EDITION_MISMATCH');
        }
        $identity = $target->sourceIdentity();
        $selection = ['repository' => $metadata['source']['repository'] ?? null,
            'requested_ref' => 'local:' . ($identity['kind'] === 'local' ? $identity['snapshot_sha256'] : $metadata['archive']['sha256']), 'channel' => 'development',
            ...($identity['kind'] === 'local' ? ['source_identity' => $identity] : ['commit' => $identity['commit'], 'tree' => $identity['tree']]),
            'template_package_path' => $metadataPath, 'template_package_sha256' => 'sha256:' . hash_file('sha256', $metadataPath)];
        return ['project_root' => $root, 'application' => $application,
            'to_manifest' => $target->path, 'source_selection' => $selection,
            'package' => ['protocol' => $metadata['protocol'], 'target' => ['version' => $target->version()],
                'edition' => $target->data['edition'], 'inventory_sha256' => 'sha256:' . $metadata['archive']['sha256'],
                'manifest_sha256' => $selection['template_package_sha256']]];
    }

    /** @return array<string,mixed> */
    private function authenticate(
        string $projectRoot,
        string $packageRoot,
        bool $requireSourceCompatibility,
    ): array {
        $project = ScaffoldPathGuard::projectRoot($projectRoot);
        $package = realpath($packageRoot);
        if (!is_string($package) || !is_dir($package) || is_link($package)) {
            throw new RuntimeException('EDITION_UPGRADE_PACKAGE_NOT_FOUND');
        }
        if (file_exists($package . '/template-package.json') || is_link($package . '/template-package.json')) {
            return $this->authenticateTemplate($project, $package);
        }
        $inventoryPath = $package . '/META-INF/files.sha256';
        if (!is_file($inventoryPath) || is_link($inventoryPath)) {
            throw new RuntimeException('EDITION_UPGRADE_INVENTORY_MISSING');
        }
        $inventory = (string) file_get_contents($inventoryPath);
        $files = $this->verifyInventory($package, $inventory);

        $manifestPath = $package . '/upgrade-manifest.json';
        if (!isset($files['upgrade-manifest.json'])) {
            throw new RuntimeException('EDITION_UPGRADE_MANIFEST_MISSING');
        }
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('EDITION_UPGRADE_MANIFEST_INVALID', 0, $exception);
        }
        if (!is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['protocol'] ?? null) !== 'peanut.edition-upgrade-package.v1'
            || ($manifest['upgrader']['scope'] ?? null) !== 'source'
            || ($manifest['upgrader']['installed_entrypoint'] ?? null) !== 'scripts/scaffold-upgrade'
            || ($manifest['upgrader']['internal_scaffold_engine'] ?? null) !== 'scripts/scaffold-upgrade'
            || isset($manifest['upgrader']['host_driver'])
            || isset($manifest['upgrader']['database_driver'])
            || !isset(
                $files['scripts/scaffold-upgrade'],
                $files['scripts/scaffold-runtime/ScaffoldPathGuard.php'],
                $files['scripts/scaffold-runtime/ScaffoldManifest.php'],
                $files['scripts/scaffold-runtime/ScaffoldUpgradeLedger.php'],
                $files['scripts/scaffold-runtime/Semver.php'],
                $files['scripts/scaffold-runtime/ScaffoldUpgradeRunner.php'],
                $files['scripts/scaffold-runtime/EditionUpgradePackage.php'],
            )) {
            throw new RuntimeException('EDITION_UPGRADE_MANIFEST_INVALID');
        }

        $application = $this->applicationManifest($project);
        $packageEdition = $this->packageEdition($manifest['edition'] ?? null);
        $edition = $packageEdition['name'];
        if (!in_array($edition, ['standalone', 'multi-tenant'], true)
            || ($application['application']['edition'] ?? null) !== $edition
            || ($application['edition']['name'] ?? null) !== $edition
            || (isset($application['edition']['tenant_bootstrap'])
                && $application['edition']['tenant_bootstrap'] !== $packageEdition['tenant_bootstrap'])) {
            throw new RuntimeException('EDITION_UPGRADE_EDITION_MISMATCH');
        }
        $current = (string) ($application['template']['version'] ?? '');
        $minimum = (string) ($manifest['compatibility']['source']['minimum_inclusive'] ?? '');
        $maximum = (string) ($manifest['compatibility']['source']['maximum_exclusive'] ?? '');
        $target = (string) ($manifest['target']['version'] ?? '');
        $majorPolicy = $manifest['compatibility']['major_policy'] ?? null;
        if (preg_match(self::VERSION, $current) !== 1
            || preg_match(self::VERSION, $minimum) !== 1
            || preg_match(self::VERSION, $maximum) !== 1
            || preg_match(self::VERSION, $target) !== 1
            || $maximum !== $target
            || !in_array($majorPolicy, ['same-major', 'source-range'], true)
            || Semver::greaterThanOrEqualTo($minimum, $target)
            || ($majorPolicy === 'same-major'
                && (explode('.', $minimum, 2)[0] !== explode('.', $target, 2)[0]
                    || explode('.', $current, 2)[0] !== explode('.', $target, 2)[0]))) {
            throw new RuntimeException('EDITION_UPGRADE_RELEASE_CHAIN_INVALID');
        }
        if (($requireSourceCompatibility || $current !== $target)
            && (Semver::lessThan($current, $minimum) || Semver::greaterThanOrEqualTo($current, $target))) {
            throw new RuntimeException(($application['generation_source']['channel'] ?? null) === 'development'
                ? 'SOURCE_RANGE_UNSUPPORTED' : 'EDITION_UPGRADE_RELEASE_CHAIN_INVALID');
        }

        $targetRelative = (string) ($manifest['target']['scaffold_manifest'] ?? '');
        ScaffoldManifest::path($targetRelative);
        if (!isset($files[$targetRelative])) {
            throw new RuntimeException('EDITION_UPGRADE_TARGET_MANIFEST_MISSING');
        }
        $targetPath = $package . '/' . $targetRelative;
        $targetManifest = ScaffoldManifest::load($targetPath);
        $targetDigest = hash_file('sha256', $targetPath);
        $release = $targetManifest->release();
        $targetEdition = $targetManifest->data['edition'] ?? null;
        if (!is_array($targetEdition)
            || !is_string($targetDigest)
            || !hash_equals((string) ($manifest['target']['scaffold_manifest_sha256'] ?? ''), $targetDigest)
            || $targetManifest->version() !== $target
            || ($targetEdition['name'] ?? null) !== $packageEdition['name']
            || ($targetEdition['deployment_mode'] ?? null) !== $packageEdition['deployment_mode']
            || ($targetEdition['source_sha256'] ?? null) !== $packageEdition['profile_sha256']
            || ($targetEdition['generator_version'] ?? null) !== $packageEdition['generator_version']
            || ($targetEdition['module_profile'] ?? null) !== $packageEdition['module_profile']
            || ($targetEdition['tenant_bootstrap'] ?? null) !== $packageEdition['tenant_bootstrap']
            || ($targetEdition['schema']['projection'] ?? null) !== $packageEdition['schema_projection']
            || $targetManifest->sourceIdentity() !== ScaffoldSourceIdentity::from($manifest['build_source'] ?? [], true)
            || ($release['inventory_sha256'] ?? null) !== ($manifest['build_source']['inventory_sha256'] ?? null)
            || ($release['managed_tree_sha256'] ?? null) !== ($manifest['target']['managed_tree_sha256'] ?? null)) {
            throw new RuntimeException('EDITION_UPGRADE_TARGET_IDENTITY_MISMATCH');
        }
        $this->assertOwnership($manifest);

        return [
            'to_manifest' => $targetManifest->path,
            'project_root' => $project,
            'application' => $application,
            'package' => $manifest + [
                'inventory_sha256' => 'sha256:' . hash('sha256', $inventory),
                'manifest_sha256' => 'sha256:' . hash_file('sha256', $manifestPath),
            ],
        ];
    }

    /** @return array{name:string,deployment_mode:string,profile_sha256:string,generator_version:int,module_profile:string,tenant_bootstrap:array<string,string>,schema_projection:string} */
    private function packageEdition(mixed $edition): array
    {
        $expectedBootstrap = [
            'kind' => 'real-default-tenant',
            'code' => 'default',
            'tenant_identity' => 'required',
            'rbac' => 'required',
            'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
            'module_lifecycle' => 'required',
        ];
        if (!is_array($edition)
            || array_keys($edition) !== [
                'name', 'deployment_mode', 'profile_sha256', 'generator_version',
                'module_profile', 'tenant_bootstrap', 'schema_projection',
            ]
            || !in_array($edition['name'] ?? null, ['standalone', 'multi-tenant'], true)
            || ($edition['deployment_mode'] ?? null) !== $edition['name']
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($edition['profile_sha256'] ?? '')) !== 1
            || !is_int($edition['generator_version'] ?? null)
            || ($edition['module_profile'] ?? null) !== 'official-default'
            || ($edition['tenant_bootstrap'] ?? null) !== $expectedBootstrap
            || ($edition['schema_projection'] ?? null) !== 'tenant-owned-v1') {
            throw new RuntimeException('EDITION_UPGRADE_EDITION_CONTRACT_INVALID');
        }
        return $edition;
    }

    /** @return array<string,string> */
    private function verifyInventory(string $root, string $contents): array
    {
        $inventory = [];
        $lines = explode("\n", $contents);
        if (array_pop($lines) !== '') {
            throw new RuntimeException('EDITION_UPGRADE_INVENTORY_INVALID');
        }
        $previous = null;
        foreach ($lines as $line) {
            $separator = strpos($line, "\0");
            if ($separator === false || strpos($line, "\0", $separator + 1) !== false) {
                throw new RuntimeException('EDITION_UPGRADE_INVENTORY_INVALID');
            }
            $path = substr($line, 0, $separator);
            $digest = substr($line, $separator + 1);
            ScaffoldManifest::path($path);
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || isset($inventory[$path])
                || ($previous !== null && strcmp($previous, $path) >= 0)) {
                throw new RuntimeException('EDITION_UPGRADE_INVENTORY_INVALID');
            }
            $absolute = $root . '/' . $path;
            if (!is_file($absolute) || is_link($absolute)
                || !hash_equals($digest, (string) hash_file('sha256', $absolute))) {
                throw new RuntimeException('EDITION_UPGRADE_FILE_DIGEST_MISMATCH: ' . $path);
            }
            $inventory[$path] = $digest;
            $previous = $path;
        }

        $actual = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                throw new RuntimeException('EDITION_UPGRADE_FILE_TYPE_INVALID');
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($relative === 'META-INF/files.sha256') {
                continue;
            }
            $actual[] = $relative;
        }
        sort($actual, SORT_STRING);
        if ($actual !== array_keys($inventory)) {
            throw new RuntimeException('EDITION_UPGRADE_INVENTORY_COVERAGE_MISMATCH');
        }
        return $inventory;
    }

    /** @return array<string,mixed> */
    private function applicationManifest(string $root): array
    {
        $path = ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json');
        try {
            $manifest = is_file($path) && !is_link($path)
                ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException $exception) {
            throw new RuntimeException('EDITION_UPGRADE_APPLICATION_MANIFEST_INVALID', 0, $exception);
        }
        if (!is_array($manifest)
            || ($manifest['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
            || !is_array($manifest['application'] ?? null)
            || !is_array($manifest['edition'] ?? null)
            || !is_array($manifest['template'] ?? null)
            || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('EDITION_UPGRADE_APPLICATION_MANIFEST_INVALID');
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function assertOwnership(array $manifest): void
    {
        if (($manifest['ownership']['automatic'] ?? null) !== ['managed', 'generated-managed']
            || ($manifest['ownership']['preserved'] ?? null) !== ['app-owned', 'third-party-module', 'secret']
            || ($manifest['recovery']['managed_files'] ?? null) !== 'scaffold-recovery-plan'
            || ($manifest['recovery']['database'] ?? null) !== 'not-applicable-source-only') {
            throw new RuntimeException('EDITION_UPGRADE_OWNERSHIP_INVALID');
        }
    }

    /** @param array<string,mixed> $manifest */

}
