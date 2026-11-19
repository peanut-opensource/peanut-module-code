<?php

declare(strict_types=1);

namespace app\platform\services;

use PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner;
use app\common\exception\BusinessException;
use app\platform\context\PlatformOperatorContext;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use think\facade\Db;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;

/**
 * Application adapter for the instance-local Tenant control plane.
 *
 * The HTTP authentication boundary supplies the validated platform context. Identity
 * still locks and verifies the active operator inside each business transaction.
 */
final readonly class TenantGovernanceService
{
    public function __construct(
        private PlatformTenantAdminService $administration,
        private TenantOwnerAdminService $owners,
        private TenantOwnerAdminProvisioner $ownerAdmins,
    ) {}

    /** @return array{tenant_id:int,account_id:int,member_id:int,role_id:int,status:string} */
    public function provision(
        PlatformOperatorContext $operator,
        string $tenantCode,
        string $tenantName,
        string $ownerEmail,
        ?string $initialPassword,
        string $ownerDisplayName,
    ): array {
        $requestId = $operator->core->requestId;
        try {
            return Db::transaction(function () use (
                $operator,
                $tenantCode,
                $tenantName,
                $ownerEmail,
                $initialPassword,
                $ownerDisplayName,
                $requestId
            ): array {
                $tenant = $this->administration->createTenant(
                    $operator->core,
                    $tenantCode,
                    $tenantName,
                    $tenantName,
                    'zh-CN',
                    'Asia/Shanghai',
                );
                $candidate = $this->owners->createCandidate(
                    $operator->core,
                    (int) $tenant['id'],
                    $ownerEmail,
                    $ownerDisplayName,
                    $initialPassword,
                );
                $member = $candidate['member'];
                $this->owners->activateCandidate(
                    $operator->core,
                    (int) $candidate['tenant_id'],
                    (int) $member['id'],
                    (int) $member['revision'],
                    $requestId . ':owner-activation',
                    'Initial Tenant owner activation',
                );
                $this->ownerAdmins->provision(
                    (int) $candidate['tenant_id'],
                    (int) $member['account_id'],
                    (int) $member['id'],
                    (int) $member['role_id'],
                    $tenantCode,
                    $ownerDisplayName,
                );

                return [
                    'tenant_id' => (int) $candidate['tenant_id'],
                    'account_id' => (int) $member['account_id'],
                    'member_id' => (int) $member['id'],
                    'role_id' => (int) $member['role_id'],
                    'status' => 'pending',
                ];
            });
        } catch (\DomainException|\InvalidArgumentException) {
            throw BusinessException::conflict(
                'TENANT_PROVISION_REJECTED',
                'Tenant provisioning was rejected.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function transition(
        PlatformOperatorContext $operator,
        int $tenantId,
        int $expectedRevision,
        TenantStatus $next,
        string $changeReason,
    ): array {
        try {
            return $this->administration->transitionTenant(
                $operator->core,
                $tenantId,
                $expectedRevision,
                $next,
                $changeReason,
            );
        } catch (\DomainException|\InvalidArgumentException) {
            [$errorCode, $message] = match ($next) {
                TenantStatus::Active => ['TENANT_ACTIVATION_REJECTED', 'Tenant activation was rejected.'],
                TenantStatus::Suspended => ['TENANT_SUSPENSION_REJECTED', 'Tenant suspension was rejected.'],
                TenantStatus::Closed => ['TENANT_CLOSURE_REJECTED', 'Tenant closure was rejected.'],
                default => ['TENANT_TRANSITION_REJECTED', 'Tenant transition was rejected.'],
            };
            throw BusinessException::conflict($errorCode, $message);
        }
    }

}
