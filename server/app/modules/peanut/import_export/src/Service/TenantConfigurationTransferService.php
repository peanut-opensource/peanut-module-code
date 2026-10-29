<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Service;

use app\common\dto\authorization\AdminPrincipal;
use app\common\contract\authorization\AdminAuthorizationQuery;
use PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferCommands;
use PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferQueries;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** Tenant Admin application boundary for configuration package operations. */
final readonly class TenantConfigurationTransferService
{
    public function __construct(
        private AdminAuthorizationQuery $authorization,
        private ConfigurationTransferCommands $commands,
        private ConfigurationTransferQueries $queries,
    ) {}

    /** @return array<string,mixed> */
    public function export(TenantContext $context, AdminPrincipal $principal): array
    {
        $this->authorize($context, $principal, 'peanut.import-export.configuration.export');
        return $this->queries->export($context, 'tenant');
    }

    /** @return array<string,mixed> */
    public function dryRun(
        TenantContext $context,
        AdminPrincipal $principal,
        array|string $package,
        array $secretBindings,
        string $conflictPolicy,
    ): array {
        $this->authorize($context, $principal, 'peanut.import-export.configuration.dry-run');
        return $this->queries->dryRun(
            $context,
            'tenant',
            $package,
            $secretBindings,
            $conflictPolicy,
        );
    }

    /** @return array<string,mixed> */
    public function apply(
        TenantContext $context,
        AdminPrincipal $principal,
        array|string $package,
        array $secretBindings,
        string $conflictPolicy,
    ): array {
        $this->authorize($context, $principal, 'peanut.import-export.configuration.apply');
        return $this->commands->apply(
            $context,
            'tenant',
            $package,
            $secretBindings,
            $conflictPolicy,
        );
    }

    private function authorize(TenantContext $context, AdminPrincipal $principal, string $permission): void
    {
        if (!$this->authorization->decide($context, $principal, $permission)->allowed) {
            throw new \runtimeException('TRANSFER_PERMISSION_DENIED');
        }
    }
}
