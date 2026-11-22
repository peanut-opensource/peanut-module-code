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
        $current = $this->executionContext()->current();
        if ($current instanceof ConsumerExecutionContext && $current->member !== null) {
            $this->memberId = $current->member->memberId;
        }
    }

    protected function memberContext(): AuthenticatedMemberContext
    {
        return $this->executionContext()->member();
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
