<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\DataPermission\Catalog\ThinkPhpResourceOperationCatalog;
use PeanutAdmin\Modules\Identity\DataPermission\Model\DataPermissionPolicyRecord;
use PeanutAdmin\Modules\Identity\DataPermission\Policy\ThinkPhpPolicyRepository;
use PeanutAdmin\Modules\Identity\Persistence\Model\Department;
use PeanutAdmin\Modules\Identity\Persistence\Model\Role;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use think\db\Raw;
use think\facade\Db;

final readonly class DataPolicyAdminService
{
    public function __construct(
        private ThinkPhpResourceOperationCatalog $catalog,
        private TargetResolverRegistry $targetResolvers,
        private AuditService $audit,
    ) {}

    public function get(int $tenantId, int $roleId, string $resourceKey, string $operation): array
    {
        $catalog = $this->operation($tenantId, $resourceKey, $operation);
        if (!Role::where('tenant_id', $tenantId)->where('id', $roleId)->where('status', 'active')->value('id')) {
            throw AdminAccessException::notFound();
        }
        $record = $this->recordQuery($tenantId, $roleId, $catalog)->find();
        if ($record === null || $record->getAttr('status') === 'archived') {
            throw AdminAccessException::notFound();
        }
        return $this->format($record->getData(), $catalog);
    }

    public function targetCardinality(int $tenantId, string $resourceKey, string $operation): string
    {
        return $this->operation($tenantId, $resourceKey, $operation)->targetCardinality;
    }

    public function catalog(int $tenantId, string $resourceKey, string $operation): array
    {
        $catalog = $this->operation($tenantId, $resourceKey, $operation);
        $conditions = [];
        foreach ($this->catalog->allowedConditions($catalog->id) as $key => $selectors) {
            foreach ($selectors as $selector) {
                $conditions[] = [
                    'condition_key' => $key,
                    'target_mode' => self::targetMode($key),
                    'selector_resource_key' => $selector,
                ];
            }
        }
        return [
            'resource_key' => $catalog->resourceKey,
            'operation' => $catalog->operation,
            'target_cardinality' => $catalog->targetCardinality,
            'conditions' => $conditions,
        ];
    }

    public function replace(
        TenantContext $actor,
        int $roleId,
        string $resourceKey,
        string $operation,
        array $payload,
        ?int $expectedRevision,
    ): array {
        self::fields($payload, ['status', 'reason', 'valid_from', 'valid_until', 'groups']);
        $status = $payload['status'] ?? null;
        $reason = $payload['reason'] ?? null;
        $from = self::date($payload['valid_from'] ?? null);
        $until = self::date($payload['valid_until'] ?? null);
        $groups = $payload['groups'] ?? null;
        if (!in_array($status, ['active', 'disabled'], true)
            || ($reason !== null && (!is_string($reason) || mb_strlen($reason) > 300))
            || ($from !== null && $until !== null && $from >= $until)
            || !is_array($groups) || !array_is_list($groups) || count($groups) > 50
            || ($status === 'active' && $groups === [])) {
            throw self::invalid();
        }

        return Db::transaction(function () use ($actor, $roleId, $resourceKey, $operation, $expectedRevision, $status, $reason, $from, $until, $groups): array {
            $tenant = Tenant::where('id', $actor->tenantId)->where('status', 'active')->lock(true)->find();
            $role = Role::where('tenant_id', $actor->tenantId)->where('id', $roleId)
                ->where('status', 'active')->lock(true)->find();
            if ($tenant === null || $role === null) {
                throw AdminAccessException::notFound();
            }
            $catalog = $this->operation($actor->tenantId, $resourceKey, $operation);
            $query = $this->recordQuery($actor->tenantId, $roleId, $catalog);
            $record = (clone $query)->lock(true)->find();
            if ($record !== null && $expectedRevision === null) {
                throw AdminAccessException::preconditionRequired();
            }
            if ($expectedRevision !== ($record === null ? null : (int) $record->getAttr('revision'))) {
                throw AdminAccessException::revisionMismatch();
            }

            $normalized = $this->normalizeGroups($actor, $catalog, $groups);
            $single = count($normalized) === 1 && count($normalized[0]['conditions']) === 1
                ? $normalized[0]['conditions'][0]
                : null;
            $scope = $single === null ? 'rules'
                : (array_search($single['key'], ThinkPhpPolicyRepository::CONDITION_KEYS, true) ?: 'rules');
            $targets = $scope === 'custom_dept' ? $single['target_ids'] : null;
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
            $data = [
                'scope_type' => $scope,
                'custom_targets_json' => $targets === null ? null : json_encode($targets, JSON_THROW_ON_ERROR),
                'condition_groups_json' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'status' => $status, 'reason' => $reason,
                'valid_from' => $from, 'valid_until' => $until,
                'updated_by_member_id' => $actor->memberId, 'updated_at' => $now,
                'revision' => ($expectedRevision ?? 0) + 1,
            ];
            if ($record === null) {
                DataPermissionPolicyRecord::insert([
                    'tenant_id' => $actor->tenantId,
                    'role_id' => $roleId,
                    'resource_key' => $catalog->resourceKey . ':' . $catalog->operation,
                    'created_by_member_id' => $actor->memberId, 'created_at' => $now,
                ] + $data);
            } else {
                (clone $query)->where('revision', $expectedRevision)->update($data);
            }
            Role::where('tenant_id', $actor->tenantId)->where('id', $roleId)
                ->update(['authorization_revision' => new Raw('authorization_revision + 1'), 'updated_at' => $now]);
            Tenant::where('id', $actor->tenantId)
                ->update(['authorization_revision' => new Raw('authorization_revision + 1'), 'updated_at' => $now]);
            $saved = $query->findOrFail()->getData();
            $this->audit->tenantMember(
                $actor, 'tenant.data_policy.replaced', 'core.role.data-policy.manage', 'role', (string) $roleId,
                metadata: ['resource_key' => $resourceKey, 'operation' => $operation, 'revision' => $data['revision']],
            );
            return $this->format($saved, $catalog);
        });
    }

    private function recordQuery(int $tenantId, int $roleId, ResourceOperation $operation): \think\db\BaseQuery
    {
        return DataPermissionPolicyRecord::where('tenant_id', $tenantId)->where('role_id', $roleId)
            ->where('resource_key', $operation->resourceKey . ':' . $operation->operation);
    }

    private function operation(int $tenantId, string $resourceKey, string $name): ResourceOperation
    {
        $operation = $this->catalog->find($resourceKey, $name);
        if ($operation === null || !$this->catalog->moduleAvailable($tenantId, $operation->moduleKey)) {
            throw AdminAccessException::notFound();
        }
        return $operation;
    }

    private function normalizeGroups(TenantContext $actor, ResourceOperation $operation, array $groups): array
    {
        $allowed = $this->catalog->allowedConditions($operation->id);
        $normalized = [];
        $groupNames = [];
        $conditionId = 0;
        foreach ($groups as $group) {
            self::fields($group, ['name', 'conditions']);
            $name = self::text($group['name'] ?? null, 120);
            $conditions = $group['conditions'] ?? null;
            if (isset($groupNames[$name]) || !is_array($conditions) || !array_is_list($conditions)
                || $conditions === [] || count($conditions) > 20) {
                throw self::invalid();
            }
            $groupNames[$name] = true;

            $prepared = [];
            foreach ($conditions as $condition) {
                self::fields($condition, ['condition_key', 'target_set', 'config']);
                $key = $condition['condition_key'] ?? null;
                if (!is_string($key) || !array_key_exists($key, $allowed)
                    || !in_array($condition['config'] ?? null, [null, []], true)
                    || ($key === 'core.tenant_all' && count($conditions) !== 1)) {
                    throw self::invalid();
                }
                $targetSet = $condition['target_set'] ?? null;
                $mode = self::targetMode($key);
                $targetKey = null;
                $targetIds = [];
                $targetName = null;
                if ($mode === 'none') {
                    if ($targetSet !== null || !in_array(null, $allowed[$key], true)) {
                        throw self::invalid();
                    }
                } else {
                    self::fields($targetSet, ['name', 'target_resource_key', 'targets']);
                    $targetKey = $targetSet['target_resource_key'] ?? null;
                    if (!is_string($targetKey) || !in_array(
                        $targetKey, $mode === 'department' ? ['core.department'] : $allowed[$key], true,
                    )) {
                        throw self::invalid();
                    }
                    $targetName = self::text($targetSet['name'] ?? null, 120);
                    $targetIds = $this->targetIds($actor, $mode, $targetKey, $targetSet['targets'] ?? null);
                }
                ++$conditionId;
                $prepared[] = [
                    'id' => $conditionId,
                    'key' => $key,
                    'target_set_id' => $targetKey === null ? null : $conditionId,
                    'target_resource_key' => $targetKey,
                    'target_ids' => $targetIds,
                    'target_name' => $targetName,
                ];
            }
            $normalized[] = ['id' => count($normalized) + 1, 'name' => $name, 'conditions' => $prepared];
        }
        return $normalized;
    }

    /** @return list<string> */
    private function targetIds(TenantContext $actor, string $mode, string $key, mixed $targets): array
    {
        if (!is_array($targets) || !array_is_list($targets) || $targets === [] || count($targets) > 500) {
            throw self::invalid();
        }
        $ids = [];
        foreach ($targets as $target) {
            self::fields($target, ['target_id']);
            $ids[] = self::text($target['target_id'] ?? null, 128);
        }
        if (count(array_unique($ids, SORT_STRING)) !== count($ids)) {
            throw self::invalid();
        }
        if ($mode === 'department') {
            foreach ($ids as $id) {
                if (preg_match('/^[1-9][0-9]{0,17}$/D', $id) !== 1) {
                    throw self::invalid();
                }
            }
            $count = Department::where('tenant_id', $actor->tenantId)->where('status', 'active')->whereIn('id', $ids)->count();
            if ((int) $count !== count($ids)) {
                throw self::invalid();
            }
        } else {
            $type = $this->catalog->targetType($key);
            if ($type === null || !$this->catalog->moduleAvailable($actor->tenantId, $type['module_key'])) {
                throw self::invalid();
            }
            $this->targetResolvers->get($type['resolver'])->resolveAndValidate($actor, new TypedResourceTargetSet($key, $ids));
        }
        return $ids;
    }

    private function format(array $row, ResourceOperation $operation): array
    {
        $scope = $row['scope_type'];
        $groups = $row['condition_groups_json'] === null
            ? [] : json_decode($row['condition_groups_json'], true, flags: JSON_THROW_ON_ERROR);
        if ($groups === [] && isset(ThinkPhpPolicyRepository::CONDITION_KEYS[$scope])) {
            $groups = [[
                'id' => 1,
                'conditions' => [[
                    'id' => 1,
                    'key' => ThinkPhpPolicyRepository::CONDITION_KEYS[$scope],
                    'target_set_id' => $scope === 'custom_dept' ? 1 : null,
                    'target_resource_key' => $scope === 'custom_dept' ? 'core.department' : null,
                    'target_ids' => $row['custom_targets_json'] === null ? []
                        : json_decode($row['custom_targets_json'], true, flags: JSON_THROW_ON_ERROR),
                ]],
            ]];
        }

        $revision = (string) $row['revision'];
        $formatted = [];
        foreach ($groups as $index => $group) {
            $conditions = [];
            foreach ($group['conditions'] as $condition) {
                $set = $condition['target_set_id'] === null ? null : [
                    'id' => (string) $condition['target_set_id'],
                    'name' => $condition['target_name'] ?? (string) $condition['target_resource_key'],
                    'target_mode' => self::targetMode($condition['key']),
                    'target_resource_key' => $condition['target_resource_key'],
                    'status' => 'active', 'revision' => $revision,
                    'targets' => array_map(static fn(int|string $id): array => ['target_id' => (string) $id], $condition['target_ids']),
                ];
                $conditions[] = [
                    'id' => (string) $condition['id'], 'condition_key' => $condition['key'],
                    'status' => 'active', 'revision' => $revision, 'target_set' => $set, 'config' => null,
                ];
            }
            $formatted[] = [
                'id' => (string) $group['id'], 'name' => $group['name'] ?? ('规则组 ' . $group['id']),
                'match_mode' => 'all', 'sort_order' => $index, 'status' => 'active',
                'revision' => $revision, 'conditions' => $conditions,
            ];
        }
        return [
            'id' => (string) $row['id'], 'tenant_id' => (string) $row['tenant_id'], 'role_id' => (string) $row['role_id'],
            'resource_key' => $operation->resourceKey, 'operation' => $operation->operation,
            'status' => $row['status'], 'valid_from' => $row['valid_from'], 'valid_until' => $row['valid_until'],
            'revision' => $revision, 'reason' => $row['reason'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'groups' => $formatted,
        ];
    }

    private static function targetMode(string $key): string
    {
        return match ($key) {
            'core.specified_departments' => 'department',
            'core.specified_objects' => 'resource',
            default => 'none',
        };
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw self::invalid();
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        } catch (\Exception) {
            throw self::invalid();
        }
    }

    private static function fields(mixed $object, array $fields): void
    {
        if (!is_array($object) || array_is_list($object) || array_diff(array_keys($object), $fields) !== []) {
            throw self::invalid();
        }
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $limit) {
            throw self::invalid();
        }
        return trim($value);
    }

    private static function invalid(): AdminAccessException
    {
        return AdminAccessException::invalid('DATA_POLICY_INPUT_INVALID', 'The data policy contains unsupported input.');
    }
}
