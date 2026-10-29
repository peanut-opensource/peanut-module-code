<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Modules\Identity\Contract\WorkflowLegacyOrganizationQuery;
use think\facade\Db;

/** Native module upgrade report. Legacy opinions/versions stay untouched; there is no inferred execution conversion. */
final readonly class WorkflowUpgradePreflight
{
    private const SCHEMA_VERSION = 20261101000000;
    public function __construct(private WorkflowLegacyOrganizationQuery $organization) {}

    public function report(): array
    {
        $tables = ['workflow_definition', 'workflow_definition_version', 'workflow_instance', 'workflow_work_item', 'workflow_event'];
        $counts = []; $missing = [];
        foreach ($tables as $table) {
            $name = Db::name($table)->getTable();
            $present = Db::query('SELECT COUNT(*) AS count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name]);
            if ((int) ($present[0]['count'] ?? 0) === 0) { $missing[] = $table; $counts[$table] = 0; }
            else { $counts[$table] = (int) Db::name($table)->count(); }
        }
        $installed = Db::name('module_installation')->where('module_key', 'peanut.workflow')
            ->whereIn('status', ['active', 'maintenance'])->count() > 0;
        $applied = $missing === [] && $installed
            && Db::name((string) config('database.migration_table', 'migrations'))->where('version', self::SCHEMA_VERSION)->count() > 0;
        $report = ['schema_version' => 1, 'module_key' => 'peanut.workflow', 'migration_version' => self::SCHEMA_VERSION, 'already_applied' => $applied,
            'table_counts' => $counts, 'missing_tables' => $missing, 'legacy_graph_count' => 0, 'legacy_all_graph_count' => 0, 'unknown_graph_count' => 0,
            'graph_details' => [], 'details_truncated' => false, 'organization' => $this->organization->leaderReport(),
            'history_preserved' => true, 'automatic_conversion' => false,
            'cutover' => 'Keep the original deployment and immutable versions while old instances finish. Stop new starts on that deployment; review a migration explicitly before switching to V2. This report never rewrites graphs, all semantics, leaders, votes or history.'];
        if ($applied) { return $report + ['status' => 'not_required', 'may_apply' => true]; }
        foreach (['workflow_definition' => 'draft_graph_json', 'workflow_definition_version' => 'graph_json'] as $table => $column) {
            if (in_array($table, $missing, true)) { continue; }
            $query = Db::name($table);
            $legacy = "(JSON_EXTRACT(`{$column}`, '$.contract_version') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, '$.contract_version')) <> '2')";
            $report['legacy_graph_count'] += (int) (clone $query)->whereRaw($legacy)->count();
            $report['legacy_all_graph_count'] += (int) (clone $query)->whereRaw("JSON_SEARCH(`{$column}`, 'one', 'all', NULL, '$**.completion_policy') IS NOT NULL")->count();
            $report['unknown_graph_count'] += (int) (clone $query)->whereRaw("JSON_TYPE(JSON_EXTRACT(`{$column}`, '$.nodes')) IS NULL OR JSON_TYPE(JSON_EXTRACT(`{$column}`, '$.nodes')) <> 'ARRAY'")->count();
            $capacity = 100 - count($report['graph_details']);
            if ($capacity > 0) {
                foreach ($query->field('id,tenant_id,' . $column)->order('id')->limit($capacity)->select()->toArray() as $row) {
                    $graph = is_array($row[$column]) ? $row[$column] : json_decode((string) $row[$column], true);
                    $report['graph_details'][] = ['table' => $table, 'id' => (string) $row['id'], 'tenant_id' => (string) $row['tenant_id'],
                        'contract_version' => is_array($graph) ? ($graph['contract_version'] ?? null) : null, 'mapping' => 'manual_review_required'];
                }
            }
        }
        $report['details_truncated'] = ($counts['workflow_definition'] + $counts['workflow_definition_version']) > count($report['graph_details']);
        $safe = array_sum($counts) === 0 && (count($missing) === 0 || count($missing) === count($tables));
        return $report + ['status' => $safe ? 'ready' : 'blocked', 'may_apply' => $safe,
            'code' => $safe ? null : 'WORKFLOW_LEGACY_DATA_REQUIRES_REVIEW'];
    }

    public function assertMayApply(): void
    {
        if (!$this->report()['may_apply']) { throw WorkflowException::upgradeRequiresReview(); }
    }

    /** Explicit maintenance export only. The host owns this PDO and its read-only consistent snapshot. */
    public function detailPages(\PDO $pdo, string $prefix, array $tenants, ?array $instances, int $limit,
        \app\common\execution\CurrentExecutionContext $contexts): \Generator
    {
        $context = $contexts->current();
        if (PHP_SAPI !== 'cli' || !$context instanceof \app\common\execution\InstanceExecutionContext
            || $context->operation() !== 'console.module:check' || $context->instance->actorKey !== 'console'
            || !$pdo->inTransaction() || $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || preg_match('/\A[a-zA-Z0-9_]*\z/D', $prefix) !== 1 || $tenants === [] || count($tenants) > 100
            || $limit < 1 || $limit > 100 || ($instances !== null && ($instances === [] || count($instances) > 100))) {
            throw new \RuntimeException('WORKFLOW_DETAIL_SCOPE_INVALID');
        }
        foreach ([...$tenants, ...($instances ?? [])] as $id) {
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) !== 1) {
                throw new \RuntimeException('WORKFLOW_DETAIL_SCOPE_INVALID');
            }
        }
        $required = [
            'workflow_definition' => ['id', 'tenant_id', 'revision', 'draft_graph_json', 'draft_graph_sha256'],
            'workflow_definition_version' => ['id', 'tenant_id', 'definition_id', 'version', 'graph_json', 'graph_sha256'],
            'workflow_instance' => ['id', 'tenant_id', 'definition_id', 'definition_version', 'subject_revision_key', 'subject_revision_sha256', 'status'],
            'workflow_work_item' => ['id', 'tenant_id', 'instance_id', 'node_key', 'assignee_member_id', 'status', 'decision'],
            'workflow_event' => ['id', 'tenant_id', 'instance_id', 'sequence_no', 'comment_text', 'comment_sha256', 'attachment_snapshots_json'],
        ];
        $schemas = [];
        foreach ($required as $table => $fields) {
            $schemas[$table] = $this->detailSchema($pdo, $prefix . $table, $fields);
        }
        if ($instances !== null && $schemas['workflow_instance']['known']) {
            $query = $pdo->prepare('SELECT id FROM `' . $prefix . 'workflow_instance` WHERE tenant_id IN ('
                . implode(',', array_fill(0, count($tenants), '?')) . ') AND id IN (' . implode(',', array_fill(0, count($instances), '?')) . ')');
            $query->execute([...$tenants, ...$instances]);
            $found = array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN));
            if (array_diff($instances, $found) !== []) {
                yield ['table' => 'workflow_instance', 'complete' => false, 'code' => 'WORKFLOW_DETAIL_INSTANCE_SCOPE_UNKNOWN',
                    'total_rows' => null, 'schema_sha256' => $schemas['workflow_instance']['sha256'], 'records' => [], 'cursor' => null];
            }
        }
        foreach ($required as $table => $_fields) {
            $schema = $schemas[$table];
            if (!$schema['known']) {
                yield ['table' => $table, 'complete' => false, 'code' => 'WORKFLOW_DETAIL_SCHEMA_UNKNOWN',
                    'total_rows' => null, 'schema_sha256' => $schema['sha256'], 'records' => [], 'cursor' => null];
                continue;
            }
            $scope = 'tenant_id IN (' . implode(',', array_fill(0, count($tenants), '?')) . ')';
            $bind = $tenants;
            if ($instances !== null && in_array($table, ['workflow_instance', 'workflow_work_item', 'workflow_event'], true)) {
                $scope .= ' AND ' . ($table === 'workflow_instance' ? 'id' : 'instance_id')
                    . ' IN (' . implode(',', array_fill(0, count($instances), '?')) . ')';
                $bind = [...$bind, ...$instances];
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM `' . $prefix . $table . '` WHERE ' . $scope);
            $count->execute($bind);
            $total = (string) $count->fetchColumn();
            if ($this->detailSchema($pdo, $prefix . $table, $_fields) !== $schema) {
                throw new \RuntimeException('WORKFLOW_DETAIL_SCHEMA_CHANGED');
            }
            $cursor = '0';
            do {
                if (!$pdo->inTransaction()) { throw new \RuntimeException('WORKFLOW_DETAIL_SNAPSHOT_LOST'); }
                $projection = [];
                foreach ($schema['columns'] as $index => $column) {
                    $quoted = '`' . str_replace('`', '``', $column) . '`';
                    $projection[] = 'SHA2(CAST(' . $quoted . ' AS BINARY),256) AS `__hash_' . $index . '`';
                    if (in_array($column, ['id', 'tenant_id', 'revision', 'version', 'definition_id', 'definition_version', 'instance_id', 'assignee_member_id', 'sequence_no'], true)) {
                        $projection[] = $quoted;
                    }
                }
                $graphColumn = $table === 'workflow_definition' ? 'draft_graph_json' : ($table === 'workflow_definition_version' ? 'graph_json' : null);
                if ($graphColumn !== null) {
                    $projection[] = 'CASE WHEN OCTET_LENGTH(`' . $graphColumn . '`)<=65535 THEN `' . $graphColumn . '` ELSE NULL END AS `' . $graphColumn . '`';
                    $digestColumn = $table === 'workflow_definition' ? 'draft_graph_sha256' : 'graph_sha256';
                    $projection[] = 'CASE WHEN `' . $digestColumn . "` REGEXP '^[a-f0-9]{64}$' THEN `" . $digestColumn . '` ELSE NULL END AS __declared_graph_sha256';
                }
                if ($table === 'workflow_event') {
                    $projection[] = "CASE WHEN comment_text IS NULL THEN comment_sha256 IS NULL ELSE comment_sha256=SHA2(CAST(comment_text AS BINARY),256) END AS __comment_matches";
                    $projection[] = "CASE WHEN JSON_TYPE(attachment_snapshots_json)='ARRAY' THEN JSON_LENGTH(attachment_snapshots_json) ELSE NULL END AS __attachment_count";
                }
                $query = $pdo->prepare('SELECT ' . implode(',', $projection) . ' FROM `' . $prefix . $table . '` WHERE ' . $scope
                    . ' AND id > ? ORDER BY id LIMIT ' . $limit);
                $query->execute([...$bind, $cursor]);
                $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
                $records = [];
                foreach ($rows as $row) {
                    $row['__fields'] = [];
                    foreach ($schema['columns'] as $index => $column) { $row['__fields'][$column] = $row['__hash_' . $index]; }
                    $records[] = $this->detailRecord($pdo, $prefix, $table, $row, $schemas, $instances);
                    $cursor = (string) $row['id'];
                }
                yield ['table' => $table, 'complete' => true, 'total_rows' => $total,
                    'schema_sha256' => $schema['sha256'], 'records' => $records, 'cursor' => $cursor,
                    'table_finished' => count($rows) < $limit];
            } while (count($rows) === $limit);
        }
    }

    private function detailSchema(\PDO $pdo, string $table, array $required): array
    {
        $query = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $query->execute([$table]);
        $columns = $query->fetchAll(\PDO::FETCH_ASSOC);
        $query = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        $engine = $query->fetchColumn();
        $query = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
        $query->execute([$table]);
        $keys = $query->fetchAll(\PDO::FETCH_COLUMN);
        $types = array_column($columns, 'COLUMN_TYPE', 'COLUMN_NAME');
        $numericKeys = true;
        foreach (array_intersect(['id', 'tenant_id', 'revision', 'version', 'definition_id', 'definition_version', 'instance_id', 'assignee_member_id', 'sequence_no'], array_keys($types)) as $key) {
            $numericKeys = $numericKeys && preg_match('/\A(?:bigint|int)(?:\([0-9]+\))? unsigned\z/D', $types[$key]) === 1;
        }
        return ['known' => $engine === 'InnoDB' && $keys === ['id'] && $numericKeys && array_diff($required, array_column($columns, 'COLUMN_NAME')) === [],
            'columns' => array_column($columns, 'COLUMN_NAME'),
            'sha256' => hash('sha256', json_encode([$columns, $engine, $keys], JSON_THROW_ON_ERROR))];
    }

    private function detailRecord(\PDO $pdo, string $prefix, string $table, array $row, array $schemas, ?array $instances): array
    {
        $fields = $row['__fields'];
        ksort($fields, SORT_STRING);
        $record = ['tenant_id' => (string) $row['tenant_id'], 'source_table' => $table, 'source_key' => (string) $row['id'],
            'rule_version' => 'workflow-preservation-observation.v1', 'source_kind' => 'database',
            'source_version' => isset($row['version']) ? (string) $row['version'] : (isset($row['revision']) ? (string) $row['revision'] : (isset($row['sequence_no']) ? (string) $row['sequence_no'] : null)),
            'source_content_sha256' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
            'content_digest_algorithm' => 'sorted-column-name-to-mysql-sha2-cast-binary-or-null.json.v1',
            'classification' => 'observed_only', 'target_key' => null, 'code' => null, 'association_counts' => []];
        foreach (['definition_id', 'definition_version', 'instance_id', 'assignee_member_id', 'sequence_no'] as $key) {
            if (isset($row[$key])) { $record[$key] = (string) $row[$key]; }
        }
        if (isset($fields['subject_revision_key'])) { $record['subject_revision_key_sha256'] = $fields['subject_revision_key']; }
        if (array_key_exists('decision', $fields)) {
            $record['decision_sha256'] = $fields['decision'];
        }
        $graphColumn = $table === 'workflow_definition' ? 'draft_graph_json' : ($table === 'workflow_definition_version' ? 'graph_json' : null);
        if ($graphColumn !== null) {
            $graph = json_decode((string) $row[$graphColumn], true);
            $record['graph_sha256'] = $fields[$graphColumn];
            $record['declared_graph_sha256'] = $row['__declared_graph_sha256'];
            $record['classification'] = 'unknown';
            $record['code'] = 'UNKNOWN_LEGACY_SEMANTICS';
            $record['node_count'] = is_array($graph['nodes'] ?? null) ? count($graph['nodes']) : null;
            $record['transition_count'] = is_array($graph['transitions'] ?? null) ? count($graph['transitions']) : null;
            if (is_array($graph) && ($graph['contract_version'] ?? null) === 2) {
                try {
                    $validated = \PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph::fromArray($graph, $table === 'workflow_definition_version');
                    if ($row['__declared_graph_sha256'] !== $validated->sha256) { throw new \RuntimeException('WORKFLOW_GRAPH_DIGEST_MISMATCH'); }
                    $record['classification'] = 'known_v2_validated_no_conversion';
                    $record['target_key'] = (string) $row['id'];
                    $record['code'] = null;
                    $record['validated_graph_sha256'] = $validated->sha256;
                } catch (\Throwable) { $record['code'] = 'WORKFLOW_GRAPH_REQUIRES_REVIEW'; }
            }
        }
        if ($table === 'workflow_event') {
            $record['comment_content_sha256'] = $fields['comment_text'];
            $record['comment_digest_matches'] = (string) $row['__comment_matches'] === '1';
            $record['attachment_snapshots_sha256'] = $fields['attachment_snapshots_json'];
            $record['association_counts']['attachment_snapshots'] = $row['__attachment_count'] === null ? null : (string) $row['__attachment_count'];
            if (!$record['comment_digest_matches'] || $record['association_counts']['attachment_snapshots'] === null) {
                $record['classification'] = 'unknown'; $record['code'] = 'WORKFLOW_HISTORY_REQUIRES_REVIEW';
            }
        }
        $links = match ($table) {
            'workflow_definition' => ['workflow_definition_version' => ['definition_id' => $row['id']], 'workflow_instance' => ['definition_id' => $row['id']]],
            'workflow_definition_version' => ['workflow_definition' => ['id' => $row['definition_id']], 'workflow_instance' => ['definition_id' => $row['definition_id'], 'definition_version' => $row['version']]],
            'workflow_instance' => ['workflow_definition_version' => ['definition_id' => $row['definition_id'], 'version' => $row['definition_version']],
                'workflow_work_item' => ['instance_id' => $row['id']], 'workflow_event' => ['instance_id' => $row['id']]],
            default => ['workflow_instance' => ['id' => $row['instance_id']]],
        };
        foreach ($links as $related => $criteria) {
            if (!$schemas[$related]['known']) { $record['association_counts'][$related] = null; continue; }
            $sql = 'SELECT COUNT(*) FROM `' . $prefix . $related . '` WHERE tenant_id=?';
            foreach ($criteria as $key => $_value) { $sql .= ' AND `' . $key . '`=?'; }
            $bindings = [(string) $row['tenant_id'], ...array_values($criteria)];
            if ($related === 'workflow_instance' && $instances !== null) {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($instances), '?')) . ')';
                $bindings = [...$bindings, ...$instances];
            }
            $query = $pdo->prepare($sql); $query->execute($bindings);
            $record['association_counts'][$related] = (string) $query->fetchColumn();
        }
        $parent = match ($table) { 'workflow_definition_version' => 'workflow_definition', 'workflow_instance' => 'workflow_definition_version',
            'workflow_work_item', 'workflow_event' => 'workflow_instance', default => null };
        if (in_array(null, $record['association_counts'], true) || ($parent !== null && ($record['association_counts'][$parent] ?? null) !== '1')) {
            $record['classification'] = 'unknown'; $record['target_key'] = null; $record['code'] = 'WORKFLOW_REFERENCE_REQUIRES_REVIEW';
        }
        $record['stop_reason'] = $record['code'];
        $record['preservation_verified_after_upgrade'] = false;
        return $record;
    }
}
