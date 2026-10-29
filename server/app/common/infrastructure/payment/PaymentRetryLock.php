<?php

declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\common\persistence\AdvisoryLockExecution;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Tenancy\TenantLockNamespace;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

/** Payment-owned adapter for the tenant-scoped retry lock. */
final readonly class PaymentRetryLock
{
    public function __construct(private AdvisoryLockExecution $locks) {}

    public function name(TenantContext $context, int $recordId): string
    {
        if ($recordId < 1) {
            throw new \InvalidArgumentException('退款记录 ID 无效');
        }

        $scope = TenantScope::fromTrustedContext(
            $context->tenantId,
            (string) ($context->requestId ?? ''),
        );

        return (new TenantLockNamespace($scope))->name('recharge:refund-retry:' . $recordId);
    }

    /** MySQL 会话级互斥覆盖完整渠道调用周期，避免快速失败时排队请求再次获准。 */
    public function run(TenantContext $context, int $recordId, callable $operation): mixed
    {
        return $this->locks->run($this->name($context, $recordId), 0, $operation);
    }
}
