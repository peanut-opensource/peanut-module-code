<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Tenant configuration transfer capability; never exposes an ORM query or table handle. */
interface TenantSettingsTransfer
{
    /** @return list<TenantSettingSnapshot> Raw documents for trusted package assembly; redact before exporting. */
    public function snapshot(TenantContext $context): array;

    public function current(TenantContext $context, string $namespace): ?TenantSettingSnapshot;

    /**
     * Participates in the caller's transaction. Null revision means create-only;
     * an existing document requires its exact planned revision, never blind overwrite.
     *
     * @param array<string, mixed> $document
     */
    public function apply(TenantContext $context, string $namespace, array $document, ?int $revision): void;
}
