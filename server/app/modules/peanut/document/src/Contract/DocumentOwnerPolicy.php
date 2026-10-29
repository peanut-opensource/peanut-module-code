<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface DocumentOwnerPolicy
{
    /** Fixed native permission inputs for the public Identity current-read fence, never a decision. */
    public function authorizationPermissionKeys(string $action): array;
    /** Validates existence, ownership, supported slot and soft-delete state; locks native owner row when requested. */
    public function resolveOwner(TenantContext $context, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState;
    /** Structure/lifecycle only, under the existing trusted tenant Scope; does not authorize an action. */
    public function resolvePersistedOwner(int $tenantId, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState;
    public function authorize(TenantContext $context, string $action, DocumentOwnerState $owner): void;
    public function stateRevision(DocumentOwnerState $owner): string;
}
