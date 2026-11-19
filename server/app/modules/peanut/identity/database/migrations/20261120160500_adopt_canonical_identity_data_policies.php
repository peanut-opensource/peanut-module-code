<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Retains operation-level policy grants, their effective condition groups and UTC validity. */
final class AdoptCanonicalIdentityDataPolicies extends Migrator
{
    private const STANDARD_SCOPES = [
        'core.tenant_all' => 'all',
        'core.department_tree' => 'dept_and_sub',
        'core.own_department' => 'dept',
        'core.self' => 'self',
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('Identity policy migration requires native PDO.');
        }
        $pdo = $adapter->getConnection();
        $legacy = $adapter->hasTable('pa_data_permission_policy');
        $policies = $legacy ? $this->sourcePolicies($pdo) : [];
        $groups = $legacy ? $this->sourceGroups($pdo) : [];

        if (!$adapter->hasTable('pa_data_permission')) {
            if (!$legacy) {
                throw new RuntimeException('Identity baseline or legacy data-policy schema is required.');
            }
            $this->createPolicyTable();
        }
        if (!$adapter->hasColumn('pa_data_permission', 'condition_groups_json')) {
            $this->execute(<<<'SQL'
ALTER TABLE pa_data_permission
  DROP CHECK chk_data_policy_scope,
  ADD COLUMN condition_groups_json json DEFAULT NULL AFTER custom_targets_json,
  ADD COLUMN valid_from datetime(3) DEFAULT NULL AFTER status,
  ADD COLUMN valid_until datetime(3) DEFAULT NULL AFTER valid_from,
  ADD CONSTRAINT chk_data_permission_scope CHECK (scope_type IN ('all','dept_and_sub','dept','custom_dept','self','rules')),
  ADD CONSTRAINT chk_data_permission_validity CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_from < valid_until)
SQL);
        }
        if ($policies === []) {
            return;
        }

        // MySQL DDL commits independently. Keep the policy copy and authorization revision changes together.
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $inserted = $this->copyPolicies($pdo, $policies, $groups);
            if ($inserted) {
                $pdo->exec('UPDATE pa_tenant SET authorization_revision = authorization_revision + 1
                    WHERE id IN (SELECT tenant_id FROM pa_data_permission_policy)');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function sourcePolicies(PDO $pdo): array
    {
        $policies = $pdo->query(<<<'SQL'
SELECT p.*, CONCAT(r.`key`, ':', o.operation) AS resource_key
  FROM pa_data_permission_policy p
  JOIN pa_protected_resource r ON r.id = p.protected_resource_id
  JOIN pa_resource_operation o ON o.id = p.resource_operation_id
 ORDER BY p.id
SQL)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($policies as $policy) {
            if (strlen($policy['resource_key']) > 160) {
                throw new RuntimeException('Data-policy operation key exceeds canonical length for policy ' . $policy['id']);
            }
        }
        return $policies;
    }

    private function sourceGroups(PDO $pdo): array
    {
        // Match the previous repository's eligibility joins. Ineligible catalog entries granted no access.
        $rows = $pdo->query(<<<'SQL'
SELECT p.id AS policy_id, g.id AS group_id, c.id AS condition_id, c.tenant_id,
       d.`key` AS condition_key, s.id AS target_set_id, s.target_resource_key
  FROM pa_data_permission_policy p
  JOIN pa_resource_operation o ON o.id = p.resource_operation_id
      AND o.protected_resource_id = p.protected_resource_id AND o.status = 'active'
  JOIN pa_data_permission_group g ON g.tenant_id = p.tenant_id AND g.data_permission_policy_id = p.id AND g.status = 'active'
  JOIN pa_data_permission_condition c ON c.tenant_id = g.tenant_id AND c.data_permission_group_id = g.id AND c.status = 'active'
  JOIN pa_data_condition_definition d ON d.id = c.condition_definition_id AND d.status = 'active'
  JOIN pa_resource_operation_condition a ON a.resource_operation_id = o.id AND a.condition_definition_id = d.id AND a.status = 'active'
  LEFT JOIN pa_data_permission_target_set s ON s.tenant_id = c.tenant_id AND s.id = c.target_set_id AND s.status = 'active'
 WHERE d.`key` <> 'core.specified_objects' OR a.selector_resource_key = s.target_resource_key
 ORDER BY p.id, g.sort_order, g.id, c.id
SQL)->fetchAll(PDO::FETCH_ASSOC);
        $targets = [];
        foreach ($pdo->query("SELECT tenant_id, target_set_id, target_id FROM pa_data_permission_target
            WHERE status = 'active' ORDER BY tenant_id, target_set_id, target_id", PDO::FETCH_ASSOC) as $target) {
            $targets[$target['tenant_id']][$target['target_set_id']][] = (string) $target['target_id'];
        }

        $groups = [];
        foreach ($rows as $row) {
            $condition = $row['condition_key'];
            $usesTargets = in_array($condition, ['core.specified_departments', 'core.specified_objects'], true);
            if (!$usesTargets && !isset(self::STANDARD_SCOPES[$condition])) {
                throw new RuntimeException('Unmapped data-policy condition on policy ' . $row['policy_id'] . ': ' . $condition);
            }
            $setId = $row['target_set_id'] === null ? null : (int) $row['target_set_id'];
            $targetIds = $setId === null ? [] : ($targets[$row['tenant_id']][$setId] ?? []);
            if (count($targetIds) > 500) {
                throw new RuntimeException('Target set requires database-backed authorization: ' . $setId);
            }
            $groups[$row['policy_id']][$row['group_id']]['id'] = (int) $row['group_id'];
            $groups[$row['policy_id']][$row['group_id']]['conditions'][] = [
                'id' => (int) $row['condition_id'],
                'key' => $condition,
                'target_set_id' => $setId,
                'target_resource_key' => $row['target_resource_key'],
                'target_ids' => $targetIds,
            ];
        }
        return array_map('array_values', $groups);
    }

    private function copyPolicies(PDO $pdo, array $policies, array $groups): bool
    {
        $find = $pdo->prepare('SELECT id, tenant_id, role_id, resource_key FROM pa_data_permission
            WHERE id = ? OR (tenant_id = ? AND role_id = ? AND resource_key = ?)');
        $insert = $pdo->prepare(<<<'SQL'
INSERT INTO pa_data_permission
    (id, tenant_id, role_id, resource_key, scope_type, condition_groups_json, status, valid_from, valid_until,
     revision, reason, created_by_member_id, updated_by_member_id, created_at, updated_at, archived_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $inserted = false;
        foreach ($policies as $policy) {
            $identity = [$policy['id'], $policy['tenant_id'], $policy['role_id'], $policy['resource_key']];
            $find->execute($identity);
            $matches = $find->fetchAll(PDO::FETCH_NUM);
            if ($matches !== []) {
                if ($matches !== [$identity]) {
                    throw new RuntimeException('Canonical data-policy identity conflict: ' . $policy['id']);
                }
                continue;
            }
            $policyGroups = $groups[$policy['id']] ?? [];
            $single = count($policyGroups) === 1 && count($policyGroups[0]['conditions']) === 1
                ? $policyGroups[0]['conditions'][0] : null;
            $scope = $single === null ? 'rules' : (self::STANDARD_SCOPES[$single['key']] ?? 'rules');
            $conditionGroups = $scope === 'rules' ? json_encode($policyGroups, JSON_THROW_ON_ERROR) : null;
            $insert->execute([
                ...$identity, $scope, $conditionGroups, $policy['status'], $policy['valid_from'], $policy['valid_until'],
                $policy['revision'], $policy['reason'], $policy['created_by_member_id'], $policy['updated_by_member_id'],
                $policy['created_at'], $policy['updated_at'], $policy['archived_at'],
            ]);
            $inserted = true;
        }
        return $inserted;
    }

    private function createPolicyTable(): void
    {
        // Legacy metadata remains available for audit. MySQL foreign-key names are schema-wide.
        $this->execute(<<<'SQL'
CREATE TABLE pa_data_permission (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint unsigned NOT NULL,
  role_id bigint unsigned NOT NULL,
  resource_key varchar(160) NOT NULL DEFAULT 'default',
  scope_type varchar(32) NOT NULL DEFAULT 'self',
  custom_targets_json json DEFAULT NULL,
  condition_groups_json json DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  valid_from datetime(3) DEFAULT NULL,
  valid_until datetime(3) DEFAULT NULL,
  revision bigint unsigned NOT NULL DEFAULT 1,
  reason varchar(300) DEFAULT NULL,
  created_by_member_id bigint unsigned NOT NULL,
  updated_by_member_id bigint unsigned NOT NULL,
  created_at datetime(3) NOT NULL,
  updated_at datetime(3) NOT NULL,
  archived_at datetime(3) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_data_policy_tenant_id (tenant_id,id),
  UNIQUE KEY uk_data_policy (tenant_id,role_id,resource_key),
  KEY idx_data_policy_active (tenant_id,role_id,status),
  KEY fk_data_permission_creator (tenant_id,created_by_member_id),
  KEY fk_data_permission_updater (tenant_id,updated_by_member_id),
  CONSTRAINT fk_data_permission_creator FOREIGN KEY (tenant_id,created_by_member_id) REFERENCES pa_tenant_member (tenant_id,id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_permission_role FOREIGN KEY (tenant_id,role_id) REFERENCES pa_role (tenant_id,id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_permission_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_data_permission_updater FOREIGN KEY (tenant_id,updated_by_member_id) REFERENCES pa_tenant_member (tenant_id,id) ON DELETE RESTRICT,
  CONSTRAINT chk_data_permission_archived CHECK (status <> 'archived' OR archived_at IS NOT NULL),
  CONSTRAINT chk_data_permission_scope CHECK (scope_type IN ('all','dept_and_sub','dept','custom_dept','self','rules')),
  CONSTRAINT chk_data_permission_status CHECK (status IN ('active','disabled','archived')),
  CONSTRAINT chk_data_permission_validity CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_from < valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Restore the paired pre-upgrade backup to restore the previous policy runtime.');
    }
}
