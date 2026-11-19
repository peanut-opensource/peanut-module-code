<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Contract;

final readonly class TenantSettingSnapshot
{
    /** @param array<string, mixed> $document */
    public function __construct(
        public int $tenantId,
        public string $namespace,
        public array $document,
        public int $revision,
        public int $createTime,
        public int $updateTime,
    ) {}
}
