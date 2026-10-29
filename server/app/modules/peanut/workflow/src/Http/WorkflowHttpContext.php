<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Http;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

/** Thin authenticated Admin HTTP adapter; object authorization remains in the workflow use case. */
final readonly class WorkflowHttpContext
{
    public function __construct(private CurrentExecutionContext $current, private AdminAuthorizationQuery $authorization) {}

    public function operation(string $permission, string $resource, string $operation): AuthorizedOperationContext
    {
        $tenant = $this->current->tenantAdmin();
        $actor = $this->authorization->principal($tenant);
        if ($actor->tenantId !== $tenant->tenantId || $actor->id !== $tenant->memberId || !$this->authorization->decide($tenant, $actor, $permission)->allowed) {
            throw WorkflowException::assignmentDenied();
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($tenant, $resource, $operation, [], hash('sha256', $tenant->requestId . ':' . $permission . ':' . $operation . ':' . $tenant->authorizationRevision)));
    }

    public function optionalOperation(string $permission, string $resource, string $operation): ?AuthorizedOperationContext
    {
        try {
            return $this->operation($permission, $resource, $operation);
        } catch (WorkflowException $exception) {
            if ($exception->httpStatus === 403) {
                return null;
            }
            throw $exception;
        }
    }
}
