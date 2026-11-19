<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\DataPermission\Model\DataPermissionPolicyRecord;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Identity\Persistence\Model\Role;
use think\db\Raw;
use think\facade\Db;

/** Lightweight standard data-scope policy service (all | dept_and_sub | dept | custom_dept | self). */
final readonly class DataPolicyAdminService
{
    public const SCOPES = ['all', 'dept_and_sub', 'dept', 'custom_dept', 'self'];

    public function __construct(
        private TargetResolverRegistry $targetResolvers,
        private AuditService $audit,
        private TenantEntitlementService $entitlements,
    ) {}

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $roleId, string $resourceKey, string $operation): array
    {
        $this->requireRole($tenantId, $roleId);
        $key = $this->policyResourceKey($resourceKey, $operation);
        $record = DataPermissionPolicyRecord::where('tenant_id', $tenantId)
            ->where('role_id', $roleId)
            ->where('resource_key', $key)
            ->find();
        if ($record === null) {
            return [
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'resource_key' => $key,
                'scope_type' => 'self',
                'custom_targets' => [],
                'status' => 'active',
                'revision' => 0,
            ];
        }
        return $this->formatRecord($record->toArray());
    }

    public function targetCardinality(int $tenantId, string $resourceKey, string $operation): string
    {
        return 'many';
    }

    public function catalog(int $tenantId, string $resourceKey, string $operation): array
    {
        return [
            'resource_key' => $resourceKey,
            'operation' => $operation,
            'target_cardinality' => 'many',
            'scopes' => self::SCOPES,
            'conditions' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function replace(
        TenantContext $actor,
        int $roleId,
        string $resourceKey,
        string $operation,
        array $payload,
        ?int $expectedRevision,
    ): array {
        $scope = (string) ($payload['scope_type'] ?? 'self');
        if (!in_array($scope, self::SCOPES, true)) {
            throw AdminAccessException::invalid('DATA_POLICY_SCOPE_INVALID', 'Invalid data permission scope.');
        }
        $customTargets = [];
        if ($scope === 'custom_dept') {
            $raw = $payload['custom_targets'] ?? $payload['department_ids'] ?? [];
            if (!is_array($raw)) {
                throw AdminAccessException::invalid('DATA_POLICY_TARGETS_INVALID', 'Custom targets must be an array.');
            }
            $customTargets = array_values(array_unique(array_map('intval', $raw)));
        }
        $key = $this->policyResourceKey($resourceKey, $operation);
        $status = (string) ($payload['status'] ?? 'active');
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw AdminAccessException::invalid('DATA_POLICY_STATUS_INVALID', 'Invalid policy status.');
        }

        return Db::transaction(function () use ($actor, $roleId, $key, $scope, $customTargets, $status, $expectedRevision, $payload): array {
            $this->requireRole($actor->tenantId, $roleId);
            $existing = DataPermissionPolicyRecord::where('tenant_id', $actor->tenantId)
                ->where('role_id', $roleId)
                ->where('resource_key', $key)
                ->lock(true)
                ->find();
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.000');
            $targetsJson = $customTargets === [] ? null : json_encode($customTargets, JSON_THROW_ON_ERROR);
            if ($existing === null) {
                if ($expectedRevision !== null && $expectedRevision !== 0) {
                    throw AdminAccessException::revisionMismatch();
                }
                $id = (int) DataPermissionPolicyRecord::insertGetId([
                    'tenant_id' => $actor->tenantId,
                    'role_id' => $roleId,
                    'resource_key' => $key,
                    'scope_type' => $scope,
                    'custom_targets_json' => $targetsJson,
                    'status' => $status,
                    'revision' => 1,
                    'reason' => isset($payload['reason']) ? (string) $payload['reason'] : null,
                    'created_by_member_id' => $actor->memberId,
                    'updated_by_member_id' => $actor->memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $after = $this->formatRecord(DataPermissionPolicyRecord::where('id', $id)->findOrFail()->toArray());
            } else {
                if ($expectedRevision !== null && (int) $existing->getAttr('revision') !== $expectedRevision) {
                    throw AdminAccessException::revisionMismatch();
                }
                DataPermissionPolicyRecord::where('id', $existing->getAttr('id'))->update([
                    'scope_type' => $scope,
                    'custom_targets_json' => $targetsJson,
                    'status' => $status,
                    'revision' => new Raw('revision + 1'),
                    'reason' => isset($payload['reason']) ? (string) $payload['reason'] : null,
                    'updated_by_member_id' => $actor->memberId,
                    'updated_at' => $now,
                ]);
                $after = $this->formatRecord(DataPermissionPolicyRecord::where('id', $existing->getAttr('id'))->findOrFail()->toArray());
            }
            $this->audit->tenant(
                $actor,
                'tenant.data_policy.replaced',
                'core.data_policy.manage',
                targetType: 'role',
                targetId: (string) $roleId,
                after: $after,
            );
            return $after;
        });
    }

    private function policyResourceKey(string $resourceKey, string $operation): string
    {
        $resourceKey = trim($resourceKey);
        $operation = trim($operation);
        if ($resourceKey === '' && $operation === '') {
            return 'default';
        }
        return $operation === '' ? $resourceKey : "{$resourceKey}:{$operation}";
    }

    private function requireRole(int $tenantId, int $roleId): void
    {
        $exists = Role::where('tenant_id', $tenantId)->where('id', $roleId)->where('status', 'active')->value('id');
        if ($exists === null) {
            throw AdminAccessException::notFound();
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatRecord(array $row): array
    {
        $targets = $row['custom_targets_json'] ?? null;
        if (is_string($targets)) {
            $targets = json_decode($targets, true, 512, JSON_THROW_ON_ERROR);
        }
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'role_id' => (int) $row['role_id'],
            'resource_key' => (string) $row['resource_key'],
            'scope_type' => (string) $row['scope_type'],
            'custom_targets' => is_array($targets) ? array_map('intval', $targets) : [],
            'status' => (string) $row['status'],
            'revision' => (int) $row['revision'],
        ];
    }
}
