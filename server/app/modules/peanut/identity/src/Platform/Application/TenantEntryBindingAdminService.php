<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Platform\Application;

use app\common\exception\BusinessException;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use think\facade\Db;

final readonly class TenantEntryBindingAdminService
{
    public function __construct(
        private PlatformAuthorizationEvaluator $authorization,
        private AuditContractHost $audit,
    ) {}

    /** @return list<array<string,mixed>> */
    public function lists(PlatformContext $context, ?int $tenantId = null): array
    {
        $this->authorization->assertAllowed($context, 'platform.tenant.read');
        $query = Db::name('tenant_entry_binding')->alias('binding')
            ->join('tenant tenant', 'tenant.id=binding.tenant_id')
            ->field('binding.id,binding.tenant_id,tenant.code AS tenant_code,tenant.name AS tenant_name,binding.host,binding.client_key,binding.status,binding.created_at,binding.updated_at');
        if ($tenantId !== null) {
            $query->where('binding.tenant_id', $tenantId);
        }
        return $query->order('binding.host')->order('binding.client_key')->order('binding.id')->select()->toArray();
    }

    /** @return array<string,mixed> */
    public function enable(
        PlatformContext $context,
        int $tenantId,
        string $host,
        string $clientKey,
        string $changeReason,
    ): array {
        $this->authorization->assertAllowed($context, 'platform.tenant.update');
        $host = TenantEntryBindingResolver::normalizeHost($host);
        $clientKey = trim($clientKey);
        if (!in_array($clientKey, [
            TenantEntryBindingResolver::ADMIN_CLIENT,
            TenantEntryBindingResolver::MEMBER_CLIENT,
        ], true)) {
            throw BusinessException::conflict(
                'TENANT_ENTRY_CLIENT_INVALID',
                'Tenant entry binding request was rejected.',
            );
        }
        if ($tenantId < 1 || trim($changeReason) === '') {
            throw BusinessException::conflict(
                'TENANT_ENTRY_INPUT_INVALID',
                'Tenant entry binding request was rejected.',
            );
        }

        return Db::transaction(function () use (
            $context,
            $tenantId,
            $host,
            $clientKey,
            $changeReason
        ): array {
            $tenantRow = Db::name('tenant')->where('id', $tenantId)->field('id,code,name,status')->lock(true)->find();
            if ($tenantRow === null || $tenantRow['status'] !== 'active') {
                throw BusinessException::conflict(
                    'TENANT_ENTRY_TENANT_UNAVAILABLE',
                    'Tenant entry binding request was rejected.',
                );
            }

            $existingRow = Db::name('tenant_entry_binding')->where('host', $host)
                ->where('client_key', $clientKey)->field('id,tenant_id,status')->lock(true)->find();
            if ($existingRow === null) {
                $bindingId = Db::name('tenant_entry_binding')->insertGetId([
                    'tenant_id' => $tenantId,
                    'host' => $host,
                    'client_key' => $clientKey,
                    'status' => 'active',
                ]);
            } else {
                $bindingId = (int) $existingRow['id'];
                if ($existingRow['status'] === 'active'
                    && (int) $existingRow['tenant_id'] !== $tenantId) {
                    throw BusinessException::conflict(
                        'TENANT_ENTRY_BINDING_CONFLICT',
                        'Tenant entry binding request was rejected.',
                    );
                }
                Db::name('tenant_entry_binding')->where('id', $bindingId)
                    ->update(['tenant_id' => $tenantId, 'status' => 'active']);
            }

            $this->audit($context, 'tenant.entry-binding.enabled', $changeReason, [
                'tenant_id' => $tenantId,
                'binding_id' => $bindingId,
                'host' => $host,
                'client_key' => $clientKey,
            ]);
            return [
                'id' => $bindingId,
                'tenant_id' => $tenantId,
                'tenant_code' => $tenantRow['code'],
                'tenant_name' => $tenantRow['name'],
                'host' => $host,
                'client_key' => $clientKey,
                'status' => 'active',
            ];
        });
    }

    /** @return array{id:int,tenant_id:int,status:string} */
    public function disable(
        PlatformContext $context,
        int $bindingId,
        string $changeReason,
    ): array {
        $this->authorization->assertAllowed($context, 'platform.tenant.update');
        if ($bindingId < 1 || trim($changeReason) === '') {
            throw BusinessException::conflict(
                'TENANT_ENTRY_INPUT_INVALID',
                'Tenant entry binding request was rejected.',
            );
        }

        return Db::transaction(function () use (
            $context,
            $bindingId,
            $changeReason
        ): array {
            $row = Db::name('tenant_entry_binding')->where('id', $bindingId)
                ->field('id,tenant_id,host,client_key,status')->lock(true)->find();
            if ($row === null) {
                throw BusinessException::conflict(
                    'TENANT_ENTRY_BINDING_NOT_FOUND',
                    'Tenant entry binding request was rejected.',
                );
            }
            if ($row['status'] !== 'disabled') {
                Db::name('tenant_entry_binding')->where('id', $bindingId)->update(['status' => 'disabled']);
            }
            $this->audit($context, 'tenant.entry-binding.disabled', $changeReason, [
                'tenant_id' => (int) $row['tenant_id'],
                'binding_id' => $bindingId,
                'host' => $row['host'],
                'client_key' => $row['client_key'],
            ]);
            return [
                'id' => $bindingId,
                'tenant_id' => (int) $row['tenant_id'],
                'status' => 'disabled',
            ];
        });
    }

    /** @param array<string,int|string> $metadata */
    private function audit(
        PlatformContext $context,
        string $eventType,
        string $reason,
        array $metadata,
    ): void {
        $this->audit->recordPlatform(
            $eventType,
            'platform.tenant.update',
            $context->requestId,
            $context->operatorId,
            $context->accountId,
            $metadata,
            AuditOutcome::Success,
            trim($reason),
        );
    }
}
