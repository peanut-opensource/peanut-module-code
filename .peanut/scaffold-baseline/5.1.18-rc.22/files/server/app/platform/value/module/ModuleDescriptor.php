<?php

declare(strict_types=1);

namespace app\platform\value\module;

/** @phpstan-type IdentityList list<array<string,mixed>> */
final readonly class ModuleDescriptor
{
    /**
     * @param array{type:string,reference:string,sha256:string} $source
     * @param list<array<string,mixed>> $composer
     * @param list<array<string,mixed>> $npm
     * @param list<array<string,mixed>> $frontend
     * @param array<string,string> $moduleRoots
     * @param array<string,mixed> $trust
     */
    public function __construct(
        public string $key,
        public string $version,
        public array $source,
        public string $lockDigest,
        public string $manifestDigest,
        public array $composer,
        public array $npm,
        public array $frontend,
        public array $moduleRoots,
        public array $trust = [],
    ) {}

    /** @return array<string,mixed> */
    public function publicIdentity(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'source' => $this->source['type'] . ':' . $this->source['reference'],
            'artifact_sha256' => $this->source['sha256'],
            'lock_digest' => $this->lockDigest,
            'status' => 'active',
            'trust' => $this->trustResult(),
        ];
    }

    /** @return array<string,mixed> */
    public function trustResult(): array
    {
        $bundled = ($this->trust['channel'] ?? null) === 'bundled';

        return [
            'status' => $bundled ? 'eligible' : 'blocked',
            'channel' => (string) ($this->trust['channel'] ?? 'unknown'),
            'license' => $this->trust['license'] ?? null,
            'origin' => $this->trust['origin'] ?? null,
            'compatibility' => $this->trust['compatibility'] ?? null,
            'blockers' => $bundled ? [] : ['PLUGIN_TRUST_QUALIFICATION_INVALID'],
        ];
    }
}
