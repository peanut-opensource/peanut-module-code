<?php

declare(strict_types=1);

namespace app\platform\value\plugin;

/** @phpstan-type IdentityList list<array<string,mixed>> */
final readonly class PluginDescriptor
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
        $qualification = is_array($this->trust['qualification'] ?? null)
            ? $this->trust['qualification']
            : [];
        $bundled = ($this->trust['channel'] ?? null) === 'bundled'
            && ($qualification['status'] ?? null) === 'bundled-locked'
            && ($qualification['marketplace'] ?? null) === 'blocked';

        return [
            'status' => $bundled ? 'eligible' : 'blocked',
            'channel' => (string) ($this->trust['channel'] ?? 'unknown'),
            'marketplace' => [
                'status' => 'blocked',
                'blockers' => [
                    'MODULE_MARKETPLACE_REVIEW_REQUIRED',
                    'MODULE_MARKETPLACE_VULNERABILITY_RESPONSE_REQUIRED',
                ],
            ],
            'archive' => $this->trust['archive'] ?? null,
            'signature' => $this->trust['signature'] ?? null,
            'sbom' => $this->trust['sbom'] ?? null,
            'license' => $this->trust['license'] ?? null,
            'origin' => $this->trust['origin'] ?? null,
            'qualification' => $qualification,
            'compatibility' => $this->trust['compatibility'] ?? null,
            'blockers' => $bundled ? [] : ['PLUGIN_TRUST_QUALIFICATION_INVALID'],
        ];
    }
}
