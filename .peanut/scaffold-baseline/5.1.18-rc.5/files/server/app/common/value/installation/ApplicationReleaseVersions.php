<?php

declare(strict_types=1);

namespace app\common\value\installation;

use app\common\infrastructure\scaffold\ReleaseDependencyIdentity;
use JsonException;
use RuntimeException;

/** Read a deployed root or generated-instance contract while keeping source and instance identities separate. */
final readonly class ApplicationReleaseVersions
{
    private const STRICT_SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    private const V1_VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D';
    private const V1_KEYS = [
        'schema_version',
        'protocol',
        'product_release',
        'scaffold_template',
        'generated_application_default',
        'core_php',
        'core_web',
    ];
    private const V2_KEYS = [
        'schema_version',
        'protocol',
        'source_product_version',
        'instance_version',
        'scaffold_template',
        'generated_instance_default',
        'core_php',
        'core_web',
    ];
    private const V3_KEYS = self::V2_KEYS;
    private const CORE_WEB_PACKAGES = [
        '@peanut-admin/client', '@peanut-admin/vue', '@peanut-admin/ui-vue',
        '@peanut-admin/nuxt', '@peanut-admin/uniapp', '@peanut-admin/testing',
    ];

    private function __construct(private array $values) {}

    /** Load exactly the supported fields while accepting harmless JSON key-order differences. */
    public static function load(string $path): self
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_INVALID');
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_INVALID', 0, $exception);
        }
        $keys = is_array($document) ? array_keys($document) : [];
        $v3 = is_array($document)
            && ($document['schema_version'] ?? null) === 3
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v3';
        $v2 = is_array($document)
            && ($document['schema_version'] ?? null) === 2
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v2';
        $expectedKeys = $v3 ? self::V3_KEYS : ($v2 ? self::V2_KEYS : self::V1_KEYS);
        if (!is_array($document) || count($keys) !== count($expectedKeys)
            || array_diff($keys, $expectedKeys) !== [] || array_diff($expectedKeys, $keys) !== []
            || (!$v2 && !$v3 && (($document['schema_version'] ?? null) !== 1
                || ($document['protocol'] ?? null) !== 'peanut.release-versions.v1'))) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_INVALID');
        }
        $values = [];
        foreach ($expectedKeys as $key) {
            $value = $document[$key];
            if ($key === 'instance_version' && $value === null) {
                $values[$key] = null;
                continue;
            }
            if ($v3 && in_array($key, ['core_php', 'core_web'], true)) {
                $values[$key] = $value;
                continue;
            }
            if (!in_array($key, ['schema_version', 'protocol'], true)
                && (!is_string($value)
                    || preg_match(($v2 || $v3) ? self::STRICT_SEMVER : self::V1_VERSION, $value) !== 1)) {
                throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_INVALID: ' . $key);
            }
            $values[$key] = $value;
        }
        if ($v2 && ($values['source_product_version'] !== $values['core_php']
                || $values['source_product_version'] !== $values['core_web']
                || $values['source_product_version'] !== $values['scaffold_template'])) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_PRODUCT_CORE_MISMATCH');
        }
        if ($v3) {
            self::assertV3Dependencies($values['core_php'], $values['core_web']);
            if ($values['source_product_version'] !== $values['scaffold_template']) {
                throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_PRODUCT_TEMPLATE_MISMATCH');
            }
        }
        return new self($values);
    }

    public function sourceProductVersion(): string
    {
        return $this->values['schema_version'] >= 2
            ? $this->values['source_product_version']
            : $this->values['product_release'];
    }

    /** Read the running instance version from the declared server or source layout. */
    public static function runningVersion(string $serverRoot): string
    {
        if (ApplicationSourceIdentity::hasRuntime($serverRoot)) {
            // Version presentation is not deployment admission. CLI maintenance still
            // verifies the complete identity; HTTP only reads its version declaration.
            if (PHP_SAPI !== 'cli') {
                $path = $serverRoot . '/' . ApplicationSourceIdentity::RUNTIME_PATH;
                if (!is_file($path) || is_link($path)) {
                    throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
                }
                $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $version = $value['versions']['release_sequence_version'] ?? null;
                if (($value['protocol'] ?? null) !== 'peanut.development-server-runtime.v1'
                    || ($value['schema_version'] ?? null) !== 1
                    || ($value['environment'] ?? null) !== 'development'
                    || !is_string($version) || preg_match(self::STRICT_SEMVER, $version) !== 1
                    || ($value['application']['version'] ?? null) !== $version) {
                    throw new RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_INVALID');
                }
                return $version;
            }
            return ApplicationSourceIdentity::load($serverRoot)->versions()['release_sequence_version'];
        }
        $identityPath = $serverRoot . '/.peanut/release-identity.json';
        if (file_exists($identityPath) || is_link($identityPath)) {
            $version = ServerReleaseIdentity::resolve($serverRoot)->versions()['release_sequence_version'];
            if (preg_match(self::STRICT_SEMVER, $version) !== 1) {
                throw new RuntimeException('APPLICATION_RELEASE_VERSION_INVALID');
            }
            return $version;
        }

        return self::load(dirname($serverRoot) . '/release-versions.json')->releaseSequenceVersion();
    }

    public function instanceVersion(): ?string
    {
        return $this->values['schema_version'] >= 2 ? $this->values['instance_version'] : null;
    }

    public function releaseSequenceVersion(): string
    {
        return $this->instanceVersion() ?? $this->sourceProductVersion();
    }

    /** Default version for a new instance; it is never the running instance identity. */
    public function generatedInstanceDefault(): string
    {
        return $this->values['schema_version'] >= 2
            ? $this->values['generated_instance_default']
            : $this->values['generated_application_default'];
    }

    public function scaffoldTemplate(): string
    {
        return $this->values['scaffold_template'];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** Runtime declarations remain valid after build inputs have left the PHP view. */
    private static function assertV3Dependencies(mixed $php, mixed $web): void
    {
        require_once dirname(__DIR__, 2) . '/infrastructure/scaffold/ReleaseDependencyIdentity.php';
        ReleaseDependencyIdentity::validate(
            $php,
            $web,
            'APPLICATION_RELEASE_VERSIONS_CORE_PHP_INVALID',
            'APPLICATION_RELEASE_VERSIONS_CORE_WEB_INVALID',
        );
    }
}
