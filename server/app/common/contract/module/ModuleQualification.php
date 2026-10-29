<?php

declare(strict_types=1);

namespace app\common\contract\module;

final readonly class ModuleQualification
{
    /** @param list<string> $dependencies */
    public function __construct(
        public string $moduleKey,
        public string $pluginKey,
        public string $version,
        public int $schemaVersion,
        public string $manifestDigest,
        public array $dependencies,
        public string $status = 'active',
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'module_key' => $this->moduleKey,
            'plugin_key' => $this->pluginKey,
            'version' => $this->version,
            'schema_version' => $this->schemaVersion,
            'manifest_digest' => $this->manifestDigest,
            'dependencies' => $this->dependencies,
            'status' => $this->status,
        ];
    }
}
