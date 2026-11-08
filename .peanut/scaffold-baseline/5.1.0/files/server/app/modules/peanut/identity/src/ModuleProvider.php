<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity;

use PeanutAdmin\Modules\Identity\access\Infrastructure\ThinkPhpTargetSetConstraintApplier;
use PeanutAdmin\Modules\Identity\access\SourceRead\ThinkPhpReadScopeAuthority;
use PeanutAdmin\Modules\Identity\Audit\Diagnostic\ThinkPhpTenantAuditDiagnosticQuery;
use PeanutAdmin\Modules\Identity\Audit\Diagnostic\ThinkPhpPlatformAuditDiagnosticQuery;
use PeanutAdmin\Modules\Identity\Identity\Query\ThinkPhpPlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Identity\Contract\PlatformAuditDiagnosticQuery;
use PeanutAdmin\Modules\Identity\Contract\PlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuditDiagnosticQuery;
use PeanutAdmin\DataPermission\Constraint\TargetSetConstraintApplier;
use PeanutAdmin\DataPermission\Scope\ReadScopeAuthority;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\DataPermission\Policy\PolicyRepository;
use PeanutAdmin\Modules\Identity\DataPermission\Catalog\ThinkPhpResourceOperationCatalog;
use PeanutAdmin\Modules\Identity\DataPermission\Policy\ThinkPhpPolicyRepository;
use PeanutAdmin\Modules\Identity\Auth\TenantSessionAccessService;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PeanutAdmin\Kernel\Audit\AuditWriter;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Module\ModuleAvailabilityService;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

/** Boot-required IAM owns the persistent implementation of Core's security boundaries. */
final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.identity';
    }

    public function bindings(): array
    {
        return [
            \PeanutAdmin\Modules\Identity\Contract\RevocationFacts::class => AuditService::class,
            AuditService::class => static fn(\think\App $app): AuditService => new AuditService(
                $app->make(\app\common\execution\CurrentExecutionContext::class),
                static function (array $fact) use ($app): void {
                    if ($app->bound(\PeanutAdmin\Modules\Identity\Contract\RevocationFactDelivery::class)) {
                        $app->make(\PeanutAdmin\Modules\Identity\Contract\RevocationFactDelivery::class)->enqueueRevocation($fact);
                    }
                },
            ),
            \PeanutAdmin\DataPermission\Provider\DepartmentHierarchyProvider::class => \PeanutAdmin\Modules\Identity\DataPermission\Provider\ThinkPhpDepartmentHierarchyProvider::class,
            \PeanutAdmin\DataPermission\Provider\TargetSetMembershipProvider::class => \PeanutAdmin\Modules\Identity\DataPermission\Provider\ThinkPhpTargetSetMembershipProvider::class,
            \PeanutAdmin\Modules\Identity\Contract\WorkflowOrganizationQuery::class => \PeanutAdmin\Modules\Identity\Identity\Query\ThinkPhpWorkflowOrganizationQuery::class,
            AuditWriter::class => AuditService::class,
            ResourceOperationCatalog::class => ThinkPhpResourceOperationCatalog::class,
            PolicyRepository::class => ThinkPhpPolicyRepository::class,
            ModuleAvailability::class => ModuleAvailabilityService::class,
            TargetSetConstraintApplier::class => ThinkPhpTargetSetConstraintApplier::class,
            ReadScopeAuthority::class => ThinkPhpReadScopeAuthority::class,
            TenantAuditDiagnosticQuery::class => ThinkPhpTenantAuditDiagnosticQuery::class,
            PlatformAuditDiagnosticQuery::class => ThinkPhpPlatformAuditDiagnosticQuery::class,
            PlatformOperatorIdentityQuery::class => ThinkPhpPlatformOperatorIdentityQuery::class,
            TenantMemberDirectory::class => ThinkPhpTenantMemberDirectory::class,
            TenantSessionAccess::class => TenantSessionAccessService::class,
        ];
    }
}
