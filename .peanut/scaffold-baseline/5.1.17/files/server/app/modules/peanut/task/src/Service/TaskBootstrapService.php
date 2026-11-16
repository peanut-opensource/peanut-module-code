<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use PeanutAdmin\Modules\Task\Contract\TaskBootstrapCommands;
use PeanutAdmin\Modules\Task\Model\Crontab;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final class TaskBootstrapService implements TaskBootstrapCommands
{
    public function seedDefaults(TenantSystemContext $context, array $defaults): void
    {
        $allowed = match ($context->actorKey) {
            'platform.tenant-bootstrap' => $context->operation === 'tenant.provision-application-defaults',
            'module.payment' => $context->operation === 'payment.provision-tenant-defaults',
            default => false,
        };
        if ($context->tenantId < 1 || $context->operationId === '' || !$allowed) {
            throw new \DomainException('TASK_PROVISION_CONTEXT_INVALID');
        }
        $existing = array_fill_keys(array_map(
            'strval',
            Crontab::withoutTenantScope()->where('tenant_id', $context->tenantId)
                ->whereIn('command', array_column($defaults, 'command'))->column('command'),
        ), true);
        $missing = array_values(array_filter(
            $defaults,
            static fn(array $row): bool => !isset($existing[$row['command']]),
        ));
        if ($missing !== []) {
            Crontab::withoutTenantScope()->insertAll(array_map(
                static fn(array $row): array => [...$row, 'tenant_id' => $context->tenantId],
                $missing,
            ));
        }
    }
}
