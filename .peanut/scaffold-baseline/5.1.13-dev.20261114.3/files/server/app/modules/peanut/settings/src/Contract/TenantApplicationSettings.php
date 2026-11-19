<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface TenantApplicationSettings
{
    public function agreement(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array;
    public function replaceAgreement(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void;
    public function statistics(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array;
    public function replaceStatistics(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void;
    public function memberProfile(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array;
    public function replaceMemberProfile(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void;
    public function login(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array;
    public function replaceLogin(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void;
    public function webPage(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array;
    public function replaceWebPage(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void;
    public function hotSearch(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array;
    public function replaceHotSearch(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void;
    public function copyright(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array;
    public function replaceCopyright(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void;
}
