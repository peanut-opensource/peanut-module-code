<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use think\facade\Db;

/** Bounded installation/maintenance diagnostic, never a membership grant or a name-to-account mapping. */
final class WorkflowLegacyOrganizationQuery
{
    public function leaderReport(): array
    {
        $table = Db::name('department')->getTable();
        $columns = Db::query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        $names = array_column($columns, 'COLUMN_NAME');
        if (!in_array('leader', $names, true)) { return ['unbound_legacy_leaders' => 0, 'department_ids' => [], 'truncated' => false, 'binding_column_present' => in_array('leader_member_id', $names, true)]; }
        $query = Db::name('department')->whereNotNull('leader')->where('leader', '<>', '');
        $bound = in_array('leader_member_id', $names, true);
        if ($bound) { $query->whereNull('leader_member_id'); }
        $count = (int) (clone $query)->count();
        $rows = $query->field('tenant_id,id')->order('tenant_id')->order('id')->limit(100)->select()->toArray();
        return ['unbound_legacy_leaders' => $count, 'department_ids' => array_map(static fn(array $row): array => ['tenant_id' => (string) $row['tenant_id'], 'department_id' => (string) $row['id']], $rows),
            'truncated' => $count > 100, 'binding_column_present' => $bound];
    }

    /** Uses only Identity tables on the host's existing read-only snapshot; never resolves names. */
    public function detailPages(\PDO $pdo, string $prefix, array $tenants, int $limit,
        \app\common\execution\CurrentExecutionContext $contexts): \Generator
    {
        $context = $contexts->current();
        if (PHP_SAPI !== 'cli' || !$context instanceof \app\common\execution\InstanceExecutionContext
            || $context->operation() !== 'console.module:check' || $context->instance->actorKey !== 'console'
            || !$pdo->inTransaction() || $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || preg_match('/\A[a-zA-Z0-9_]*\z/D', $prefix) !== 1 || $tenants === [] || count($tenants) > 100 || $limit < 1 || $limit > 100) {
            throw new \RuntimeException('IDENTITY_DETAIL_SCOPE_INVALID');
        }
        foreach ($tenants as $id) {
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) !== 1) {
                throw new \RuntimeException('IDENTITY_DETAIL_SCOPE_INVALID');
            }
        }
        $schemas = [];
        $known = true;
        foreach (['tenant' => ['id'], 'department' => ['id', 'tenant_id', 'status', 'revision'],
            'tenant_member' => ['id', 'tenant_id', 'account_id', 'status'], 'account' => ['id', 'status']] as $table => $required) {
            $query = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
            $query->execute([$prefix . $table]); $columns = $query->fetchAll(\PDO::FETCH_ASSOC);
            $query = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $query->execute([$prefix . $table]); $engine = $query->fetchColumn();
            $query = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
            $query->execute([$prefix . $table]); $keys = $query->fetchAll(\PDO::FETCH_COLUMN);
            $schemas[$table] = ['columns' => array_column($columns, 'COLUMN_NAME'), 'definition' => [$columns, $engine, $keys]];
            $known = $known && $engine === 'InnoDB' && $keys === ['id'] && array_diff($required, $schemas[$table]['columns']) === [];
            $types = array_column($columns, 'COLUMN_TYPE', 'COLUMN_NAME');
            foreach (array_intersect(['id', 'tenant_id', 'revision', 'leader_member_id'], $schemas[$table]['columns']) as $key) {
                $known = $known && preg_match('/\A(?:bigint|int)(?:\([0-9]+\))? unsigned\z/D', $types[$key]) === 1;
            }
            if ($known) {
                // Pin table metadata for this snapshot before reading business rows.
                $pdo->query('SELECT id FROM `' . $prefix . $table . '` WHERE 1=0')->closeCursor();
                $query = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
                $query->execute([$prefix . $table]);
                $known = $known && $query->fetchAll(\PDO::FETCH_ASSOC) === $columns;
                $query = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $query->execute([$prefix . $table]);
                $known = $known && $query->fetchColumn() === $engine;
                $query = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
                $query->execute([$prefix . $table]);
                $known = $known && $query->fetchAll(\PDO::FETCH_COLUMN) === $keys;
            }
        }
        $schemaSha = hash('sha256', json_encode($schemas, JSON_THROW_ON_ERROR));
        $columns = $schemas['department']['columns'];
        if (!$known || (!in_array('leader', $columns, true) && !in_array('leader_member_id', $columns, true))) {
            yield ['table' => 'department', 'complete' => false, 'code' => 'IDENTITY_DETAIL_SCHEMA_UNKNOWN',
                'schema_sha256' => $schemaSha, 'total_rows' => null, 'records' => [], 'cursor' => null];
            return;
        }
        $scope = 'tenant_id IN (' . implode(',', array_fill(0, count($tenants), '?')) . ')';
        $query = $pdo->prepare('SELECT id FROM `' . $prefix . 'tenant` WHERE id IN (' . implode(',', array_fill(0, count($tenants), '?')) . ')');
        $query->execute($tenants);
        if (array_diff($tenants, array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN))) !== []) {
            yield ['table' => 'department', 'complete' => false, 'code' => 'IDENTITY_DETAIL_TENANT_SCOPE_UNKNOWN',
                'schema_sha256' => $schemaSha, 'total_rows' => null, 'records' => [], 'cursor' => null];
        }
        $query = $pdo->prepare('SELECT COUNT(*) FROM `' . $prefix . 'department` WHERE ' . $scope);
        $query->execute($tenants); $total = (string) $query->fetchColumn();
        $cursor = '0';
        do {
            if (!$pdo->inTransaction()) { throw new \RuntimeException('IDENTITY_DETAIL_SNAPSHOT_LOST'); }
            $projection = ['id', 'tenant_id', 'revision', "CASE WHEN status='active' THEN 1 ELSE 0 END AS __department_active"];
            foreach ($columns as $index => $column) {
                $projection[] = 'SHA2(CAST(`' . str_replace('`', '``', $column) . '` AS BINARY),256) AS `__hash_' . $index . '`';
            }
            if (in_array('leader_member_id', $columns, true)) { $projection[] = 'leader_member_id'; }
            if (in_array('leader', $columns, true)) { $projection[] = 'CASE WHEN leader IS NOT NULL AND OCTET_LENGTH(leader)>0 THEN 1 ELSE 0 END AS __has_text'; }
            $query = $pdo->prepare('SELECT ' . implode(',', $projection) . ' FROM `' . $prefix . 'department` WHERE ' . $scope . ' AND id > ? ORDER BY id LIMIT ' . $limit);
            $query->execute([...$tenants, $cursor]); $rows = $query->fetchAll(\PDO::FETCH_ASSOC); $records = [];
            foreach ($rows as $row) {
                $fields = [];
                foreach ($columns as $index => $column) { $fields[$column] = $row['__hash_' . $index]; }
                ksort($fields, SORT_STRING);
                $binding = $row['leader_member_id'] ?? null;
                $valid = null;
                if ($binding !== null) {
                    // Same active member + active account predicates as the native Workflow organization query.
                    $member = $pdo->prepare('SELECT COUNT(*) FROM `' . $prefix . 'tenant_member` member JOIN `' . $prefix
                        . "account` account ON account.id=member.account_id AND account.status='active' WHERE member.tenant_id=? AND member.id=? AND member.status='active'");
                    $member->execute([(string) $row['tenant_id'], (string) $binding]);
                    $valid = (string) $row['__department_active'] === '1' && (string) $member->fetchColumn() === '1';
                }
                $hasText = (string) ($row['__has_text'] ?? '') === '1';
                $code = $binding !== null ? ($valid ? null : 'LEADER_BINDING_INVALID')
                    : ($hasText ? 'LEADER_IDENTITY_UNRESOLVED' : 'LEADER_BINDING_NOT_PROVEN');
                $records[] = ['tenant_id' => (string) $row['tenant_id'], 'source_table' => 'department',
                    'source_key' => (string) $row['id'], 'source_version' => (string) $row['revision'],
                    'source_kind' => 'database', 'rule_version' => 'identity-binding-observation.v1',
                    'source_content_sha256' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
                    'content_digest_algorithm' => 'sorted-column-name-to-mysql-sha2-cast-binary-or-null.json.v1',
                    'leader_text_sha256' => $fields['leader'] ?? null,
                    'stored_member_id' => $binding === null ? null : (string) $binding,
                    'binding_valid' => $valid, 'candidate_count' => null,
                    'classification' => $valid === true ? 'existing_binding_valid_no_conversion' : 'unknown',
                    'target_key' => $valid === true ? (string) $binding : null, 'code' => $code, 'stop_reason' => $code,
                    'association_counts' => ['valid_stored_binding' => $binding === null ? null : ($valid ? 1 : 0)],
                    'preservation_verified_after_upgrade' => false];
                $cursor = (string) $row['id'];
            }
            yield ['table' => 'department', 'complete' => true, 'total_rows' => $total, 'schema_sha256' => $schemaSha,
                'records' => $records, 'cursor' => $cursor, 'table_finished' => count($rows) < $limit];
        } while (count($rows) === $limit);
    }
}
