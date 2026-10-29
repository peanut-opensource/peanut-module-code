<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use app\command\ModuleCheck;
use PDO;

/** Owner-only metadata observations for the explicit development maintenance command, never user access. */
final readonly class DocumentUpgradePreservationQuery
{
    public function preservationPages(PDO $pdo, string $prefix, array $tenants, ?array $documents, int $limit, ModuleCheck $host): \Generator
    {
        $scope = $host->assertPreservationReadSnapshot($pdo, 'peanut.document');
        if ($scope['prefix'] !== $prefix || $scope['tenants'] !== $tenants || $scope['documents'] !== $documents || $scope['limit'] !== $limit) {
            throw new \RuntimeException('DOCUMENT_PRESERVATION_SCOPE_INVALID');
        }
        $this->assertScope($prefix, $tenants, $documents, $limit);
        $required = [
            'document' => ['id', 'tenant_id', 'content_kind', 'status', 'state_seq', 'auth_revision'],
            'document_state' => ['id', 'tenant_id', 'document_id', 'state_seq', 'state_sha256', 'yjs_state_binary', 'projected_json'],
            'document_revision' => ['id', 'tenant_id', 'document_id', 'revision_no', 'source_state_seq', 'content_kind', 'snapshot_sha256', 'immutable_file_key', 'immutable_file_sha256', 'immutable_file_mime_type', 'yjs_snapshot', 'content_snapshot_json'],
            'document_file_ref' => ['id', 'tenant_id', 'document_id', 'file_key', 'variant_key', 'usage', 'derived_state_seq'],
            'document_resource_binding' => ['id', 'tenant_id', 'document_id', 'primary_file_key', 'source_sha256', 'source_file_revision', 'state_seq', 'verified_mime_type'],
        ];
        $schemas = [];
        foreach ($required as $table => $fields) { $schemas[$table] = $this->schema($pdo, $prefix . $table, $fields); }
        foreach ($required as $table => $fields) {
            if ($schemas[$table] !== $this->schema($pdo, $prefix . $table, $fields)) { throw new \RuntimeException('DOCUMENT_SCHEMA_CHANGED'); }
        }
        if ($documents !== null && $schemas['document']['known']) {
            $query = $pdo->prepare('SELECT id FROM `' . $prefix . 'document` WHERE tenant_id IN (' . implode(',', array_fill(0, count($tenants), '?'))
                . ') AND id IN (' . implode(',', array_fill(0, count($documents), '?')) . ')');
            $query->execute([...$tenants, ...$documents]);
            if (array_diff($documents, array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN))) !== []) {
                yield ['table' => 'document', 'complete' => false, 'code' => 'DOCUMENT_SCOPE_UNKNOWN', 'total_rows' => null, 'records' => [], 'cursor' => null];
            }
        }
        foreach ($required as $table => $_fields) {
            $schema = $schemas[$table];
            if (!$schema['known']) {
                yield ['table' => $table, 'complete' => false, 'code' => 'DOCUMENT_SCHEMA_UNKNOWN', 'schema_sha256' => $schema['sha256'],
                    'total_rows' => null, 'records' => [], 'cursor' => null];
                continue;
            }
            $scope = 'tenant_id IN (' . implode(',', array_fill(0, count($tenants), '?')) . ')'; $bindings = $tenants;
            if ($documents !== null) {
                $scope .= ' AND ' . ($table === 'document' ? 'id' : 'document_id') . ' IN (' . implode(',', array_fill(0, count($documents), '?')) . ')';
                $bindings = [...$bindings, ...$documents];
            }
            $query = $pdo->prepare('SELECT COUNT(*) FROM `' . $prefix . $table . '` WHERE ' . $scope); $query->execute($bindings);
            $total = (string) $query->fetchColumn(); $cursor = '0';
            do {
                $host->assertPreservationReadSnapshot($pdo, 'peanut.document');
                $projection = [];
                foreach ($schema['columns'] as $index => $column) {
                    $quoted = '`' . str_replace('`', '``', $column) . '`';
                    $projection[] = 'SHA2(CAST(' . $quoted . ' AS BINARY),256) AS `__hash_' . $index . '`';
                    if (in_array($column, ['id', 'tenant_id', 'document_id', 'revision_no', 'source_state_seq', 'state_seq', 'auth_revision', 'source_file_revision', 'derived_state_seq'], true)) { $projection[] = $quoted; }
                    if (in_array($column, ['content_kind', 'status', 'snapshot_sha256', 'state_sha256', 'immutable_file_key', 'immutable_file_sha256', 'file_key', 'primary_file_key', 'source_sha256', 'immutable_file_mime_type', 'verified_mime_type'], true)) {
                        $projection[] = 'CASE WHEN OCTET_LENGTH(' . $quoted . ')<=128 THEN ' . $quoted . ' ELSE NULL END AS ' . $quoted;
                    }
                }
                $query = $pdo->prepare('SELECT ' . implode(',', $projection) . ' FROM `' . $prefix . $table . '` WHERE ' . $scope . ' AND id>? ORDER BY id LIMIT ' . $limit);
                $query->execute([...$bindings, $cursor]); $rows = $query->fetchAll(PDO::FETCH_ASSOC); $records = [];
                foreach ($rows as $row) {
                    $hashes = [];
                    foreach ($schema['columns'] as $index => $column) { $hashes[$column] = $row['__hash_' . $index]; }
                    ksort($hashes, SORT_STRING);
                    $documentId = (string) ($row['document_id'] ?? $row['id']);
                    $record = ['tenant_id' => (string) $row['tenant_id'], 'document_id' => $documentId, 'source_table' => $table,
                        'source_key' => (string) $row['id'], 'rule_version' => 'document-preservation-observation.v1',
                        'source_content_sha256' => hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR)),
                        'content_digest_algorithm' => 'sorted-column-name-to-mysql-sha2-cast-binary-or-null.json.v1',
                        'classification' => 'metadata_observed', 'code' => null, 'references' => [], 'bytes_verified' => false];
                    foreach (['revision_no', 'source_state_seq', 'state_seq', 'auth_revision', 'source_file_revision', 'derived_state_seq'] as $key) { if (isset($row[$key])) { $record[$key] = (string) $row[$key]; } }
                    foreach (['snapshot_sha256', 'state_sha256'] as $key) {
                        if (array_key_exists($key, $row)) {
                            $record[$key] = is_string($row[$key]) && preg_match('/\A[a-f0-9]{64}\z/D', $row[$key]) === 1 ? $row[$key] : null;
                            if ($record[$key] === null) { $record['code'] = 'DOCUMENT_DIGEST_INVALID'; }
                        }
                    }
                    if (isset($row['content_kind'])) {
                        $record['content_kind'] = in_array($row['content_kind'], ['rich_text', 'file_resource'], true) ? $row['content_kind'] : null;
                        if ($record['content_kind'] === null) { $record['code'] = 'DOCUMENT_KIND_INVALID'; }
                    }
                    if ($table === 'document_revision' && ($row['content_kind'] ?? null) === 'rich_text'
                        && ($hashes['content_snapshot_json'] === null || $row['immutable_file_key'] !== null)) { $record['code'] = 'DOCUMENT_REVISION_SHAPE_INVALID'; }
                    if ($table === 'document') {
                        $record['status'] = in_array($row['status'] ?? null, ['active', 'archiving', 'archived', 'blocked_recovery'], true) ? $row['status'] : null;
                        if ($record['status'] === null) { $record['code'] = 'DOCUMENT_STATE_UNKNOWN'; }
                    } elseif ($schemas['document']['known']) {
                        $parent = $pdo->prepare('SELECT state_seq FROM `' . $prefix . 'document` WHERE tenant_id=? AND id=?');
                        $parent->execute([(string) $row['tenant_id'], $documentId]); $parents = $parent->fetchAll(PDO::FETCH_COLUMN);
                        $record['parent_count'] = (string) count($parents);
                        if ($record['parent_count'] !== '1') { $record['code'] = 'DOCUMENT_PARENT_MISSING'; }
                        else {
                            $currentSeq = (string) $parents[0];
                            foreach (['source_state_seq', 'state_seq', 'derived_state_seq'] as $sequence) {
                                if (isset($row[$sequence])) {
                                    $originalSeq = (string) $row[$sequence];
                                    if (strlen($originalSeq) > strlen($currentSeq) || (strlen($originalSeq) === strlen($currentSeq) && strcmp($originalSeq, $currentSeq) > 0)) { $record['code'] = 'DOCUMENT_SEQUENCE_REFERENCE_INVALID'; }
                                }
                            }
                        }
                    } else { $record['parent_count'] = null; $record['code'] = 'DOCUMENT_PARENT_UNKNOWN'; }
                    $fileColumn = match ($table) { 'document_revision' => 'immutable_file_key', 'document_file_ref' => 'file_key', 'document_resource_binding' => 'primary_file_key', default => null };
                    $needsFile = $fileColumn !== null && ($table !== 'document_revision' || ($row['content_kind'] ?? null) === 'file_resource' || $row[$fileColumn] !== null);
                    if ($needsFile) {
                        $rawKey = $row[$fileColumn];
                        $validKey = is_string($rawKey) && preg_match('/\Afile_[a-f0-9]{32}\z/D', $rawKey) === 1;
                        $digestColumn = $table === 'document_revision' ? 'immutable_file_sha256' : ($table === 'document_resource_binding' ? 'source_sha256' : null);
                        $expected = $digestColumn === null ? null : ($row[$digestColumn] ?? null);
                        $validDigest = $digestColumn === null || (is_string($expected) && preg_match('/\A[a-f0-9]{64}\z/D', $expected) === 1);
                        $mimeColumn = $table === 'document_revision' ? 'immutable_file_mime_type' : ($table === 'document_resource_binding' ? 'verified_mime_type' : null);
                        $validMime = $mimeColumn === null || (is_string($row[$mimeColumn] ?? null)
                            && preg_match('~\A[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+\z~D', $row[$mimeColumn]) === 1);
                        $record['references'][] = ['tenant_id' => (string) $row['tenant_id'], 'document_id' => $documentId,
                            'source_table' => $table, 'source_key' => (string) $row['id'], 'file_key' => $validKey ? $rawKey : null,
                            'file_key_field_sha256' => $hashes[$fileColumn], 'expected_sha256' => $validDigest ? $expected : null,
                            'expected_revision' => isset($row['source_file_revision']) ? (string) $row['source_file_revision'] : null,
                            'expected_mime_sha256' => $mimeColumn === null ? null : $hashes[$mimeColumn],
                            'requires_immutable' => $table === 'document_revision', 'valid' => $validKey && $validDigest && $validMime];
                        if (!$validKey || !$validDigest || !$validMime) { $record['code'] = 'DOCUMENT_FILE_REFERENCE_INVALID'; }
                    }
                    if ($record['code'] !== null) { $record['classification'] = 'unknown'; }
                    $records[] = $record; $cursor = (string) $row['id'];
                }
                yield ['table' => $table, 'complete' => true, 'total_rows' => $total, 'schema_sha256' => $schema['sha256'],
                    'records' => $records, 'cursor' => $cursor, 'table_finished' => count($rows) < $limit];
            } while (count($rows) === $limit);
        }
    }

    private function assertScope(string $prefix, array $tenants, ?array $documents, int $limit): void
    {
        if (preg_match('/\A[a-zA-Z0-9_]*\z/D', $prefix) !== 1 || $tenants === [] || count($tenants) > 100
            || count(array_unique($tenants)) !== count($tenants) || $limit < 1 || $limit > 100
            || ($documents !== null && ($documents === [] || count($documents) > 100 || count(array_unique($documents)) !== count($documents)))) {
            throw new \RuntimeException('DOCUMENT_PRESERVATION_SCOPE_INVALID');
        }
        foreach ([...$tenants, ...($documents ?? [])] as $id) {
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) !== 1) { throw new \RuntimeException('DOCUMENT_PRESERVATION_SCOPE_INVALID'); }
        }
    }

    private function schema(PDO $pdo, string $table, array $required): array
    {
        $query = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $query->execute([$table]); $columns = $query->fetchAll(PDO::FETCH_ASSOC);
        $query = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]); $engine = $query->fetchColumn();
        $query = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
        $query->execute([$table]); $keys = $query->fetchAll(PDO::FETCH_COLUMN);
        $known = $engine === 'InnoDB' && $keys === ['id'] && array_diff($required, array_column($columns, 'COLUMN_NAME')) === [];
        foreach ($columns as $column) {
            if (in_array($column['COLUMN_NAME'], ['id', 'tenant_id', 'document_id', 'revision_no', 'state_seq', 'source_state_seq', 'auth_revision', 'source_file_revision', 'derived_state_seq'], true)) {
                $known = $known && preg_match('/\A(?:bigint|int)(?:\([0-9]+\))? unsigned\z/D', $column['COLUMN_TYPE']) === 1;
                $known = $known && $column['IS_NULLABLE'] === 'NO';
            }
        }
        if ($known) { $pdo->query('SELECT id FROM `' . $table . '` WHERE 1=0')->closeCursor(); }
        return ['known' => $known, 'columns' => array_column($columns, 'COLUMN_NAME'),
            'sha256' => hash('sha256', json_encode([$columns, $engine, $keys], JSON_THROW_ON_ERROR))];
    }
}
