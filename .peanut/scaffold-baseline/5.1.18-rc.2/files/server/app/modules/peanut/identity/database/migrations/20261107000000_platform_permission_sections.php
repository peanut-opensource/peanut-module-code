<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Split existing platform capabilities without resetting instance bindings or role grants. */
final class PlatformPermissionSections extends Migrator
{
    // Non-null sources inherit equivalent subsets; null only registers an independently granted capability.
    private const SPLITS = [
        'platform.permission.api.read' => 'platform.permission.read',
        'platform.permission.menu.read' => 'platform.permission.read',
        'platform.permission.bundle.manage' => 'platform.permission.api.manage',
        'platform.permission.menu.manage' => 'platform.permission.api.manage',
        'platform.storage.account.read' => 'platform.ops.read',
        'platform.storage.space.read' => 'platform.ops.read',
        'platform.storage.route.read' => 'platform.ops.read',
        'platform.storage.legacy.read' => null,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('PlatformPermissionSections requires the native PDO adapter.');
        }
        $pdo = $adapter->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $changedRoles = [];
            foreach (self::SPLITS as $key => $sourceKey) {
                $target = $this->permission($pdo, $key);
                if ($target === false) {
                    $insert = $pdo->prepare("INSERT INTO pa_permission (`key`,module_key,type,name,risk_level,status,manifest_version,created_at,updated_at) VALUES (?, 'platform', 'api', ?, 'normal', 'active', '0.1.0', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))");
                    $insert->execute([$key, $key]);
                    $target = $this->permission($pdo, $key);
                }
                if ($target['module_key'] !== 'platform' || $target['status'] !== 'active') {
                    throw new RuntimeException('PLATFORM_PERMISSION_SECTION_IDENTITY_CONFLICT: ' . $key);
                }
                if ($sourceKey === null) {
                    continue;
                }
                $source = $this->permission($pdo, $sourceKey);
                if ($source === false || $source['status'] !== 'active') {
                    continue;
                }
                if ($source['module_key'] !== 'platform') {
                    throw new RuntimeException('PLATFORM_PERMISSION_SECTION_SOURCE_CONFLICT: ' . $sourceKey);
                }
                $config = $pdo->prepare('SELECT configured FROM pa_permission_api_config WHERE permission_id = ? FOR UPDATE');
                $config->execute([$source['id']]);
                $sourceConfigured = (int) $config->fetchColumn() === 1;
                $config->execute([$target['id']]);
                $targetConfigured = (int) $config->fetchColumn() === 1;
                if ($sourceConfigured || $targetConfigured) {
                    // Neither customized side may turn an equivalent split into an unrelated API grant.
                    continue;
                }
                // Missing config has the same source-default meaning as configured=0 in ApiPermissionCatalog.
                // Scope synchronization may already have moved the old API bindings; they are not user overrides.
                $roles = $pdo->prepare('SELECT role_record.id FROM pa_platform_role role_record JOIN pa_platform_role_permission grant_row ON grant_row.platform_role_id = role_record.id WHERE grant_row.permission_id = ? ORDER BY role_record.id FOR UPDATE');
                $roles->execute([$source['id']]);
                $roleIds = $roles->fetchAll(PDO::FETCH_COLUMN);
                if ($roleIds === []) {
                    continue;
                }
                foreach ($roleIds as $roleId) {
                    $insert = $pdo->prepare('INSERT INTO pa_platform_role_permission (platform_role_id,permission_id,granted_at) SELECT ?, ?, UTC_TIMESTAMP(3) WHERE NOT EXISTS (SELECT 1 FROM pa_platform_role_permission WHERE platform_role_id = ? AND permission_id = ?)');
                    $insert->execute([$roleId, $target['id'], $roleId, $target['id']]);
                    if ($insert->rowCount() === 1) {
                        $changedRoles[(int) $roleId] = true;
                    }
                }
            }
            $update = $pdo->prepare('UPDATE pa_platform_role SET revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = ?');
            foreach (array_keys($changedRoles) as $roleId) {
                $update->execute([$roleId]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function permission(PDO $pdo, string $key): array|false
    {
        $query = $pdo->prepare('SELECT id,module_key,status FROM pa_permission WHERE `key` = ? FOR UPDATE');
        $query->execute([$key]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Platform permission definitions and instance role grants are retained.');
    }
}
