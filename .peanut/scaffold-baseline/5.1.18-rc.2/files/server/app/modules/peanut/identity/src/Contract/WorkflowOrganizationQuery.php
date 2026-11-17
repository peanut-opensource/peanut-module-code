<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Bounded organization projection; membership never grants object access. */
interface WorkflowOrganizationQuery
{
    /** @return list<array{member_id:int,source_kind:string,source_key:string,binding_revision:string}> */
    public function resolve(TenantContext $actor, string $kind, string $key, ?string $departmentScope = null): array;

    /** Rechecks active account/member and at least one frozen source, without changing the snapshot. */
    public function eligible(TenantContext $actor, int $memberId, array $sources): bool;

    /** A requested department must be the actor's current direct business department; no first-match guessing. */
    public function businessDepartment(TenantContext $actor, ?string $requestedKey): ?string;

    /** Validates an owning business object's department reference; inactive/missing returns null, ambiguity rejects. */
    public function activeDepartmentKey(TenantContext $actor, string $key): ?string;
}
