<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Integration;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** Owned by each business provider; current native object scope, independent of workflow participation. */
interface WorkflowScenarioAccess
{
    public function authorize(AuthorizedOperationContext $basis, string $resourceKey, string $operation, array $permissionKeys, string $subjectKey): AuthorizedOperationContext;

    /** Trusted host passes an already tenant-bound query and a fixed subject column, never HTTP SQL. */
    public function applyVisibleSubjectScope(\PeanutAdmin\Kernel\Auth\TenantContext $ctx, string $resourceKey, \think\db\BaseQuery $query, string $subjectColumn): void;
}
