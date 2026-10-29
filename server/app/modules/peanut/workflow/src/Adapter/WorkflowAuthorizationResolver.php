<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowAuthorizationResolver
{
    /** Native provider SQL constraints, applied before count and pagination. */
    public function applyVisibleScopes(AuthorizedOperationContext $trustedBasis, \think\db\BaseQuery $query): void;

    /** @param non-empty-list<string> $permissionKeys */
    public function authorize(
        AuthorizedOperationContext $trustedBasis,
        string $resourceKey,
        string $operation,
        array $permissionKeys,
        string $subjectKey,
    ): AuthorizedOperationContext;
}
