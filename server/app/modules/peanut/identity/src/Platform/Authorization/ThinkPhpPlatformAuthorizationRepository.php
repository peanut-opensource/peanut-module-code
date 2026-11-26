<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Platform\Authorization;

use JsonException;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformOperator;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformOperatorRole;

final class ThinkPhpPlatformAuthorizationRepository implements \PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository
{
    public function revision(int $operatorId): string
    {
        $record = PlatformOperator::where('id', $operatorId)
            ->field('status,security_revision')
            ->find();
        $operator = $record?->toArray();
        if ($operator === null) {
            return hash('sha256', "missing:{$operatorId}");
        }
        $roles = PlatformOperatorRole::alias('operator_role')
            ->join('platform_role role', 'role.id = operator_role.platform_role_id')
            ->where('operator_role.platform_operator_id', $operatorId)
            ->field('role.id,role.status,role.revision')
            ->order('role.id')
            ->select()
            ->toArray();

        try {
            return hash('sha256', json_encode(['operator' => $operator, 'roles' => $roles], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', "invalid:{$operatorId}");
        }
    }

    public function permissions(int $operatorId): EffectivePermissionSet
    {
        $facts = $this->roleFacts($operatorId);
        $permissions = [];
        $ownerPermissions = [];
        foreach ($facts['roles'] as $role) {
            foreach ($role['permission_keys'] as $key) {
                $permissions[] = $key;
            }
            if ($role['owner_dynamic']) {
                $ownerPermissions = self::bootstrapOwnerPermissionKeys();
            }
        }

        return new EffectivePermissionSet([...$permissions, ...$ownerPermissions]);
    }

    /**
     * @return array{status: ?string, account_id: ?int, roles: array<int, array{
     *     status: ?string, permission_keys: list<string>, unresolved_grant: bool, owner_dynamic: bool
     * }>}
     */
    public function roleFacts(int $operatorId): array
    {
        // One global source switch: no per-role old/direct merge or runtime dual read.
        $direct = new PlatformRoleDirectGrantStore();
        if ($direct->source() === 'direct') {
            return $direct->roleFacts($operatorId);
        }

        $rows = PlatformOperator::alias('operator')
            ->leftJoin('platform_operator_role operator_role', 'operator_role.platform_operator_id = operator.id')
            ->leftJoin('platform_role role', 'role.id = operator_role.platform_role_id')
            ->leftJoin('platform_role_permission role_permission', "role_permission.platform_role_id = role.id AND role.status = 'active'")
            ->leftJoin(
                'permission permission',
                "permission.id = role_permission.permission_id AND permission.status = 'active' AND permission.key LIKE 'platform.%'",
            )
            ->where('operator.id', $operatorId)
            ->field([
                'operator.status' => 'operator_status', 'operator.account_id',
                'operator_role.platform_role_id' => 'role_id',
                'role.key' => 'role_key', 'role.status' => 'role_status', 'role.is_builtin',
                'role_permission.permission_id' => 'granted_permission_id', 'permission.key' => 'permission_key',
            ])
            ->distinct(true)
            ->order('role.key')
            ->order('permission.key')
            ->select()
            ->toArray();

        $status = $rows[0]['operator_status'] ?? null;
        $roles = [];
        foreach ($rows as $row) {
            if ($row['role_id'] === null) {
                continue;
            }

            $roleId = (int) $row['role_id'];
            $active = $status === 'active' && $row['role_status'] === 'active';
            $roles[$roleId] ??= [
                'status' => $row['role_status'],
                'permission_keys' => [],
                'unresolved_grant' => false,
                'owner_dynamic' => $active && $row['role_key'] === 'platform.bootstrap-owner'
                    && (int) $row['is_builtin'] === 1,
            ];
            if ($active && is_string($row['permission_key'])) {
                $roles[$roleId]['permission_keys'][] = $row['permission_key'];
            }
            if ($active && $row['granted_permission_id'] !== null && $row['permission_key'] === null) {
                $roles[$roleId]['unresolved_grant'] = true;
            }
        }

        return [
            'status' => $status,
            'account_id' => $status === null ? null : (int) $rows[0]['account_id'],
            'roles' => $roles,
        ];
    }

    /** @return list<string> */
    public static function bootstrapOwnerPermissionKeys(): array
    {
        return CorePermissionCatalog::PLATFORM;
    }
}
