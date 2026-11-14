<?php

declare(strict_types=1);

namespace app\common\runtime\org;

use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PeanutAdmin\Modules\Identity\Identity\SelfService\AccountSelfService;
use PeanutAdmin\Modules\Identity\Membership\Application\MemberAdminService;

/** Composition root for native Tenant administration services. */
final readonly class TenantAdminRuntime
{
    public function __construct(
        private MemberAdminService $members,
        private AccountSelfService $selfService,
        private DemoAccountPolicy $demoAccounts,
    ) {}

    public function members(): MemberAdminService
    {
        return $this->members;
    }

    public function selfService(): AccountSelfService
    {
        return $this->selfService;
    }

    public function assertPasswordChangeAllowed(int $accountId): void
    {
        $this->demoAccounts->assertPasswordChangeAllowed($accountId);
    }
}
