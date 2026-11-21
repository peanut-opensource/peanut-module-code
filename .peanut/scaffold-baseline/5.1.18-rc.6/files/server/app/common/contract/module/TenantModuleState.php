<?php

declare(strict_types=1);

namespace app\common\contract\module;

final readonly class TenantModuleState
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $moduleKey,
        public string $status,
        public string $source,
        public int $configRevision,
        public ?string $effectiveAt,
        public ?string $expiresAt,
        public ?string $enabledAt,
        public ?string $disabledAt,
        public ?string $disabledReason,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['tenant_id'] ?? 0),
            (string) ($row['module_key'] ?? ''),
            (string) ($row['status'] ?? ''),
            (string) ($row['source'] ?? ''),
            (int) ($row['config_revision'] ?? 0),
            self::nullable($row['effective_at'] ?? null),
            self::nullable($row['expires_at'] ?? null),
            self::nullable($row['enabled_at'] ?? null),
            self::nullable($row['disabled_at'] ?? null),
            self::nullable($row['disabled_reason'] ?? null),
            self::nullable($row['created_at'] ?? null),
            self::nullable($row['updated_at'] ?? null),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'module_key' => $this->moduleKey,
            'status' => $this->status,
            'source' => $this->source,
            'config_revision' => $this->configRevision,
            'effective_at' => $this->effectiveAt,
            'expires_at' => $this->expiresAt,
            'enabled_at' => $this->enabledAt,
            'disabled_at' => $this->disabledAt,
            'disabled_reason' => $this->disabledReason,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
