<?php

declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\traits\ApiResponseTrait;
use app\common\execution\ConsumerExecutionContext;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

abstract class BaseApiController extends BaseController
{
    use ApiResponseTrait;

    protected int   $memberId   = 0;
    protected array $memberInfo = [];

    public function initialize(): void
    {
        $current = $this->executionContext();
        if ($current->current() instanceof ConsumerExecutionContext
            && $current->current()->member !== null) {
            $this->memberId = $current->memberId();
        }
    }

    protected function memberContext(): AuthenticatedMemberContext
    {
        $context = $this->executionContext()->member();
        if (!$context instanceof AuthenticatedMemberContext) {
            throw new \DomainException('EXECUTION_MEMBER_CONTEXT_REQUIRED');
        }
        return $context;
    }

    protected function publicTenantContext(string $operation): TenantSystemContext
    {
        $context = $this->executionContext()->consumer()->publicTenant;
        if ($context === null || $context->operation !== $operation) {
            throw new \DomainException('EXECUTION_PUBLIC_TENANT_CONTEXT_REQUIRED');
        }
        return $context;
    }
}
