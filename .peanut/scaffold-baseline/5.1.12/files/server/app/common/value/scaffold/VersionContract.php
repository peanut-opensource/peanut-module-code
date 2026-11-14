<?php

declare(strict_types=1);

namespace app\common\value\scaffold;

require_once dirname(__DIR__, 2) . '/infrastructure/scaffold/ReleaseDependencyIdentity.php';

use app\common\infrastructure\scaffold\ReleaseDependencyIdentity;
use Composer\Semver\VersionParser;
use RuntimeException;

/** Root source-product authority. V3 records package identities independently from the product version. */
final readonly class VersionContract
{
    private const STRICT_SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
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
        '@peanut-admin/client',
        '@peanut-admin/vue',
        '@peanut-admin/ui-vue',
        '@peanut-admin/nuxt',
        '@peanut-admin/uniapp',
        '@peanut-admin/testing',
    ];

    private function __construct(private array $values) {}

    public static function load(string $path): self
    {
        self::loadSemver($path);
        $raw = file_get_contents($path);
        try {
            $values = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $exception) {
            throw new RuntimeException('VERSION_CONTRACT_INVALID_JSON', 0, $exception);
        }
        if (!is_array($values)) {
            throw new RuntimeException('VERSION_CONTRACT_SCHEMA_INVALID');
        }
        $v3 = ($values['schema_version'] ?? null) === 3
            && ($values['protocol'] ?? null) === 'peanut.release-versions.v3';
        $v2 = ($values['schema_version'] ?? null) === 2
            && ($values['protocol'] ?? null) === 'peanut.release-versions.v2';
        if ((!$v2 && !$v3 && (array_keys($values) !== self::V1_KEYS
                || ($values['schema_version'] ?? null) !== 1
                || ($values['protocol'] ?? null) !== 'peanut.release-versions.v1'))
            || ($v2 && array_keys($values) !== self::V2_KEYS)
            || ($v3 && array_keys($values) !== self::V3_KEYS)) {
            throw new RuntimeException('VERSION_CONTRACT_SCHEMA_INVALID');
        }
        $parser = new VersionParser();
        $versionKeys = $v3
            ? ['source_product_version', 'scaffold_template', 'generated_instance_default']
            : ($v2
                ? ['source_product_version', 'scaffold_template', 'generated_instance_default', 'core_php', 'core_web']
                : array_slice(self::V1_KEYS, 2));
        foreach ($versionKeys as $key) {
            if (!is_string($values[$key]) || $values[$key] === '') {
                throw new RuntimeException('VERSION_CONTRACT_VERSION_INVALID: ' . $key);
            }
            if (($v2 || $v3) && preg_match(self::STRICT_SEMVER, $values[$key]) !== 1) {
                throw new RuntimeException('VERSION_CONTRACT_VERSION_INVALID: ' . $key);
            }
            if (!$v2 && !$v3) {
                try {
                    $parser->normalize($values[$key]);
                } catch (\UnexpectedValueException $exception) {
                    throw new RuntimeException('VERSION_CONTRACT_VERSION_INVALID: ' . $key, 0, $exception);
                }
            }
        }
        if ($v2 || $v3) {
            if ($values['instance_version'] !== null) {
                throw new RuntimeException('VERSION_CONTRACT_ROOT_INSTANCE_VERSION_INVALID');
            }
            foreach ($v2 ? ['core_php', 'core_web', 'scaffold_template'] : ['scaffold_template'] as $key) {
                if ($values[$key] !== $values['source_product_version']) {
                    throw new RuntimeException('VERSION_CONTRACT_PRODUCT_CORE_VERSION_MISMATCH');
                }
            }
        }
        if ($v3) {
            self::assertV3Dependencies($values['core_php'] ?? null, $values['core_web'] ?? null, dirname($path));
        }
        return new self($values);
    }

    public function sourceProductVersion(): string
    {
        return ($this->isV2() || $this->isV3()) ? $this->values['source_product_version'] : $this->values['product_release'];
    }

    public function isV2(): bool
    {
        return $this->values['schema_version'] === 2;
    }

    public function isV3(): bool
    {
        return $this->values['schema_version'] === 3;
    }

    public function scaffoldTemplate(): string
    {
        return $this->values['scaffold_template'];
    }

    /** Default version assigned to a newly generated customer instance. */
    public function generatedInstanceDefault(): string
    {
        return ($this->isV2() || $this->isV3())
            ? $this->values['generated_instance_default']
            : $this->values['generated_application_default'];
    }

    public function corePhp(): string
    {
        return $this->isV3() ? $this->values['core_php']['resolved_version'] : $this->values['core_php'];
    }

    public function coreWeb(): string
    {
        return $this->isV3() ? $this->values['core_web']['packages']['@peanut-admin/client']['version'] : $this->values['core_web'];
    }

    /** @return array{package:string,constraint:string,resolved_version:string,source_type:string,source_url:string,source_reference:string} */
    public function corePhpPackage(): array
    {
        if (!$this->isV3()) {
            return [
                'package' => 'peanut-admin/core',
                'constraint' => $this->corePhp(),
                'resolved_version' => $this->corePhp(),
                'source_type' => 'release',
                'source_url' => '',
                'source_reference' => '',
            ];
        }
        return $this->values['core_php'];
    }

    /** @return array<string,string> */
    public function coreWebPackages(): array
    {
        return $this->isV3()
            ? array_map(static fn(array $package): string => $package['version'], $this->values['core_web']['packages'])
            : array_fill_keys(self::CORE_WEB_PACKAGES, $this->coreWeb());
    }

    /** @return array<string,mixed> */
    public function coreWebIdentity(): array
    {
        return $this->isV3()
            ? $this->values['core_web']
            : ['packages' => array_map(
                static fn(string $version): array => ['version' => $version],
                $this->coreWebPackages(),
            )];
    }

    public function assertSame(string $actual, string $expected, string $error): void
    {
        if (($this->isV2() || $this->isV3())
            && (preg_match(self::STRICT_SEMVER, $actual) !== 1
                || preg_match(self::STRICT_SEMVER, $expected) !== 1)) {
            throw new RuntimeException($error);
        }
        if (!$this->isV2() && !$this->isV3()) {
            $parser = new VersionParser();
            try {
                $parser->normalize($actual);
                $parser->normalize($expected);
            } catch (\UnexpectedValueException $exception) {
                throw new RuntimeException($error, 0, $exception);
            }
        }
        if ($actual !== $expected) {
            throw new RuntimeException($error . ": expected {$expected}, got {$actual}");
        }
    }

    public function assertValid(string $version, string $error): void
    {
        if (($this->isV2() || $this->isV3()) && preg_match(self::STRICT_SEMVER, $version) !== 1) {
            throw new RuntimeException($error);
        }
        if ($this->isV2() || $this->isV3()) {
            return;
        }
        try {
            (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    private static function loadSemver(string $contractPath): void
    {
        if (class_exists(VersionParser::class)) {
            return;
        }
        $root = dirname($contractPath);
        $autoload = $root . '/server/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('VERSION_CONTRACT_COMPOSER_AUTOLOAD_UNAVAILABLE');
        }
        require_once $autoload;
    }

    private static function assertV3Dependencies(mixed $php, mixed $web, string $root): void
    {
        ReleaseDependencyIdentity::validate(
            $php,
            $web,
            'VERSION_CONTRACT_CORE_PHP_INVALID',
            'VERSION_CONTRACT_CORE_WEB_INVALID',
        );
        ReleaseDependencyIdentity::verifyArchives($root, $web, 'VERSION_CONTRACT_CORE_WEB_ARCHIVE_INVALID');
    }

}
