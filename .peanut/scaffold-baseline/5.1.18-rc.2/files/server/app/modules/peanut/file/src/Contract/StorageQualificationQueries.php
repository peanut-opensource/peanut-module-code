<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use think\facade\Db;

/** 受信平台配置检查的固定投影；保留原摘要，不返回密文、路径或存储模型，不访问外部存储。 */
final readonly class StorageQualificationQueries
{
    /** Only File-owned metadata, on the actual maintenance host's existing read-only snapshot. */
    public function preservationPages(\PDO $pdo, string $prefix, array $tenants, int $limit, \app\command\ModuleCheck $host): \Generator
    {
        $scope = $this->preservationAccess($pdo, $prefix, $tenants, $limit, $host);
        if ($scope['file_scope'] !== 'all-in-selected-tenants') { throw new \RuntimeException('FILE_PRESERVATION_SCOPE_INVALID'); }
        foreach (['file_object' => 'id', 'file_immutable_snapshot' => 'file_key'] as $table => $key) {
            $schema = $this->preservationSchema($pdo, $prefix, $table);
            if ($schema !== $this->preservationSchema($pdo, $prefix, $table)) { $schema['known'] = false; }
            if (!$schema['known']) {
                yield ['table' => $table, 'complete' => false, 'code' => 'FILE_SCHEMA_UNKNOWN', 'total_rows' => null,
                    'schema_sha256' => $schema['sha256'], 'records' => [], 'cursor' => null];
                continue;
            }
            $filter = 'tenant_id IN (' . implode(',', array_fill(0, count($tenants), '?')) . ')';
            $query = $pdo->prepare('SELECT COUNT(*) FROM `' . $prefix . $table . '` WHERE ' . $filter); $query->execute($tenants);
            $total = (string) $query->fetchColumn(); $cursor = $key === 'id' ? '0' : '';
            do {
                $this->preservationAccess($pdo, $prefix, $tenants, $limit, $host);
                $ordered = $key === 'id' ? 'id' : 'CAST(file_key AS BINARY)';
                $query = $pdo->prepare('SELECT ' . $this->preservationProjection($schema['columns']) . ' FROM `' . $prefix . $table . '` WHERE '
                    . $filter . ' AND ' . $ordered . ' > ' . ($key === 'id' ? '?' : 'CAST(? AS BINARY)') . ' ORDER BY ' . $ordered . ' LIMIT ' . $limit);
                $query->execute([...$tenants, $cursor]); $rows = $query->fetchAll(\PDO::FETCH_ASSOC); $records = [];
                foreach ($rows as $row) {
                    $records[] = $this->preservationRecord($row, $schema['columns'], $table);
                    $cursor = (string) $row[$key];
                }
                yield ['table' => $table, 'complete' => true, 'total_rows' => $total, 'schema_sha256' => $schema['sha256'],
                    'records' => $records, 'cursor' => $key === 'id' || preg_match('/\Afile_[a-f0-9]{32}\z/D', $cursor) === 1 ? $cursor : null,
                    'cursor_sha256' => hash('sha256', $cursor), 'ordering' => $key === 'id' ? 'unsigned id' : 'binary file_key', 'table_finished' => count($rows) < $limit];
            } while (count($rows) === $limit);
        }
    }

    /** Every input gets a result, including malformed or missing references. No external storage or Doc table reads. */
    public function documentReferenceMetadata(\PDO $pdo, string $prefix, array $tenants, array $references, \app\command\ModuleCheck $host): array
    {
        $scope = $host->assertPreservationReadSnapshot($pdo, 'peanut.file');
        $this->preservationAccess($pdo, $prefix, $tenants, $scope['limit'], $host);
        $host->assertPreservationReferenceBatch($pdo, $references);
        if (count($references) > 100) { throw new \RuntimeException('FILE_REFERENCE_BATCH_INVALID'); }
        $objectSchema = $this->preservationSchema($pdo, $prefix, 'file_object');
        $snapshotSchema = $this->preservationSchema($pdo, $prefix, 'file_immutable_snapshot');
        if ($objectSchema !== $this->preservationSchema($pdo, $prefix, 'file_object')) { $objectSchema['known'] = false; }
        if ($snapshotSchema !== $this->preservationSchema($pdo, $prefix, 'file_immutable_snapshot')) { $snapshotSchema['known'] = false; }
        $results = [];
        foreach ($references as $reference) {
            $result = ['tenant_id' => $reference['tenant_id'] ?? null, 'document_id' => $reference['document_id'] ?? null,
                'source_table' => $reference['source_table'] ?? null, 'source_key' => $reference['source_key'] ?? null,
                'file_key' => null, 'classification' => 'unknown', 'code' => 'DOCUMENT_FILE_REFERENCE_INVALID', 'bytes_verified' => false];
            $key = $reference['file_key'] ?? null;
            if (($reference['valid'] ?? null) !== true || !is_string($key) || preg_match('/\Afile_[a-f0-9]{32}\z/D', $key) !== 1
                || !in_array($reference['tenant_id'] ?? null, $tenants, true)) { $results[] = $result; continue; }
            $result['file_key'] = $key;
            if (!$objectSchema['known'] || (($reference['requires_immutable'] ?? false) && !$snapshotSchema['known'])) {
                $result['code'] = 'FILE_SCHEMA_UNKNOWN'; $results[] = $result; continue;
            }
            $query = $pdo->prepare('SELECT ' . $this->preservationProjection($objectSchema['columns']) . ' FROM `' . $prefix . 'file_object` WHERE tenant_id=? AND file_key=? LIMIT 2');
            $query->execute([$reference['tenant_id'], $key]); $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                // Do not read another tenant to distinguish missing from cross-tenant. Both remain inaccessible.
                $result['code'] = $rows === [] ? 'FILE_REFERENCE_MISSING_OR_OUTSIDE_TENANT' : 'FILE_REFERENCE_AMBIGUOUS'; $results[] = $result; continue;
            }
            $row = $rows[0];
            $object = $this->preservationRecord($row, $objectSchema['columns'], 'file_object'); $result['object'] = $object;
            $result['code'] = $object['code'];
            if (($reference['expected_sha256'] ?? null) !== null && ($object['sha256'] ?? null) !== $reference['expected_sha256']) { $result['code'] = 'FILE_REFERENCE_DIGEST_MISMATCH'; }
            if (($reference['expected_revision'] ?? null) !== null && ($object['revision'] ?? null) !== $reference['expected_revision']) { $result['code'] = 'FILE_REFERENCE_REVISION_MISMATCH'; }
            if (($reference['expected_mime_sha256'] ?? null) !== null && ($object['media_type_sha256'] ?? null) !== $reference['expected_mime_sha256']) { $result['code'] = 'FILE_REFERENCE_MIME_MISMATCH'; }
            if (($reference['requires_immutable'] ?? false) === true) {
                $query = $pdo->prepare('SELECT ' . $this->preservationProjection($snapshotSchema['columns']) . ' FROM `' . $prefix . 'file_immutable_snapshot` WHERE tenant_id=? AND file_key=?');
                $query->execute([$reference['tenant_id'], $key]); $snapshotRow = $query->fetch(\PDO::FETCH_ASSOC);
                if ($snapshotRow === false) { $result['code'] = 'FILE_IMMUTABLE_REFERENCE_MISSING'; }
                else {
                    $snapshot = $this->preservationRecord($snapshotRow, $snapshotSchema['columns'], 'file_immutable_snapshot'); $result['immutable_snapshot'] = $snapshot;
                    if ($snapshot['code'] !== null) { $result['code'] = $snapshot['code']; }
                    if (($snapshot['status'] ?? null) !== 'claimed' || ($snapshot['claimed_document_id'] ?? null) !== $reference['document_id']) { $result['code'] = 'FILE_IMMUTABLE_CLAIM_MISMATCH'; }
                    if (($snapshot['source_sha256'] ?? null) !== ($object['sha256'] ?? null)) { $result['code'] = 'FILE_IMMUTABLE_DIGEST_MISMATCH'; }
                    if (($object['document_snapshot'] ?? false) !== true || ($object['access_type'] ?? null) !== 'private') { $result['code'] = 'FILE_IMMUTABLE_OBJECT_KIND_MISMATCH'; }
                    if (($reference['expected_mime_sha256'] ?? null) !== ($snapshot['verified_mime_type_sha256'] ?? null)) { $result['code'] = 'FILE_IMMUTABLE_MIME_MISMATCH'; }
                    // Historical source_revision is a captured fact; never require today's mutable source revision to match it.
                }
            }
            $result['object_ready'] = ($object['status'] ?? null) === 'ready';
            if (!$result['object_ready']) { $result['code'] = 'FILE_REFERENCE_OBJECT_NOT_READY'; }
            $result['classification'] = $result['code'] === null ? 'metadata_reference_consistent' : 'unknown'; $results[] = $result;
        }
        return $results;
    }

    private function preservationAccess(\PDO $pdo, string $prefix, array $tenants, int $limit, \app\command\ModuleCheck $host): array
    {
        $scope = $host->assertPreservationReadSnapshot($pdo, 'peanut.file');
        if ($scope['prefix'] !== $prefix || $scope['tenants'] !== $tenants || $scope['limit'] !== $limit
            || $tenants === [] || count($tenants) > 100 || $limit < 1 || $limit > 100 || preg_match('/\A[a-zA-Z0-9_]*\z/D', $prefix) !== 1) {
            throw new \RuntimeException('FILE_PRESERVATION_SCOPE_INVALID');
        }
        foreach ($tenants as $tenant) { if (!is_string($tenant) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $tenant) !== 1) { throw new \RuntimeException('FILE_PRESERVATION_SCOPE_INVALID'); } }
        return $scope;
    }

    private function preservationSchema(\PDO $pdo, string $prefix, string $table): array
    {
        $required = $table === 'file_object' ? ['id', 'tenant_id', 'file_key', 'revision', 'sha256', 'media_type', 'size_bytes', 'status', 'purpose', 'access_type']
            : ['tenant_id', 'file_key', 'source_file_key', 'source_revision', 'source_sha256', 'status', 'claimed_document_id', 'verified_mime_type'];
        $query = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $query->execute([$prefix . $table]); $columns = $query->fetchAll(\PDO::FETCH_ASSOC);
        $query = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $query->execute([$prefix . $table]); $engine = $query->fetchColumn();
        $query = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
        $query->execute([$prefix . $table]); $keys = $query->fetchAll(\PDO::FETCH_COLUMN);
        $known = $engine === 'InnoDB' && $keys === [$table === 'file_object' ? 'id' : 'file_key'] && array_diff($required, array_column($columns, 'COLUMN_NAME')) === [];
        foreach ($columns as $column) {
            if (in_array($column['COLUMN_NAME'], ['id', 'tenant_id', 'revision', 'source_revision', 'claimed_document_id', 'size_bytes'], true)) {
                $known = $known && preg_match('/\A(?:bigint|int)(?:\([0-9]+\))? unsigned\z/D', $column['COLUMN_TYPE']) === 1;
            }
            if ($column['COLUMN_NAME'] === 'file_key') { $known = $known && preg_match('/\A(?:var)?char\(64\)\z/D', $column['COLUMN_TYPE']) === 1; }
            if (in_array($column['COLUMN_NAME'], ['id', 'tenant_id', 'file_key', 'revision', 'source_revision', 'size_bytes'], true)) { $known = $known && $column['IS_NULLABLE'] === 'NO'; }
        }
        if ($known) { $pdo->query('SELECT file_key FROM `' . $prefix . $table . '` WHERE 1=0')->closeCursor(); }
        return ['known' => $known, 'columns' => array_column($columns, 'COLUMN_NAME'), 'sha256' => hash('sha256', json_encode([$columns, $engine, $keys], JSON_THROW_ON_ERROR))];
    }

    private function preservationProjection(array $columns): string
    {
        $projection = [];
        foreach ($columns as $index => $column) {
            $quoted = '`' . str_replace('`', '``', $column) . '`';
            $projection[] = 'SHA2(CAST(' . $quoted . ' AS BINARY),256) AS `__hash_' . $index . '`';
            if (in_array($column, ['id', 'tenant_id', 'file_key', 'source_file_key', 'revision', 'source_revision', 'sha256', 'source_sha256', 'status', 'size_bytes', 'claimed_document_id', 'purpose', 'access_type'], true)) {
                $projection[] = 'CASE WHEN OCTET_LENGTH(' . $quoted . ')<=128 THEN ' . $quoted . ' ELSE NULL END AS ' . $quoted;
            }
        }
        return implode(',', $projection);
    }

    private function preservationRecord(array $row, array $columns, string $table): array
    {
        $fields = [];
        foreach ($columns as $index => $column) { $fields[$column] = $row['__hash_' . $index]; } ksort($fields, SORT_STRING);
        $record = ['source_table' => $table, 'tenant_id' => (string) $row['tenant_id'], 'rule_version' => 'file-preservation-observation.v1',
            'source_content_sha256' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
            'content_digest_algorithm' => 'sorted-column-name-to-mysql-sha2-cast-binary-or-null.json.v1', 'bytes_verified' => false,
            'classification' => 'metadata_observed', 'code' => null];
        foreach (['file_key', 'source_file_key'] as $key) {
            if (array_key_exists($key, $row)) {
                $record[$key] = is_string($row[$key]) && preg_match('/\Afile_[a-f0-9]{32}\z/D', $row[$key]) === 1 ? $row[$key] : null;
                if ($record[$key] === null) { $record['code'] = 'FILE_KEY_INVALID'; }
            }
        }
        foreach (['sha256', 'source_sha256'] as $key) {
            if (array_key_exists($key, $row)) {
                $record[$key] = is_string($row[$key]) && preg_match('/\A[a-f0-9]{64}\z/D', $row[$key]) === 1 ? $row[$key] : null;
                if ($record[$key] === null) { $record['code'] = 'FILE_DIGEST_UNKNOWN'; }
            }
        }
        foreach (['id', 'revision', 'source_revision', 'size_bytes', 'claimed_document_id'] as $key) {
            if (array_key_exists($key, $row)) {
                $record[$key] = $row[$key] !== null && preg_match('/\A[0-9]{1,20}\z/D', (string) $row[$key]) === 1 ? (string) $row[$key] : null;
            }
        }
        $record['status'] = in_array($row['status'] ?? null, $table === 'file_object' ? ['writing', 'ready', 'write_failed', 'archived'] : ['captured', 'claimed', 'cleanup_pending', 'abandoned'], true) ? $row['status'] : null;
        if ($table === 'file_object') {
            $record['media_type_sha256'] = $fields['media_type'];
            $record['document_snapshot'] = in_array($row['purpose'] ?? null, ['document.snapshot', 'document.snapshot-sensitive'], true);
            $record['access_type'] = in_array($row['access_type'] ?? null, ['private', 'public'], true) ? $row['access_type'] : null;
            if ($record['access_type'] === null || $record['media_type_sha256'] === null) { $record['code'] = 'FILE_METADATA_UNKNOWN'; }
        } else { $record['verified_mime_type_sha256'] = $fields['verified_mime_type']; }
        if ($record['status'] === null) { $record['code'] = 'FILE_STATE_UNKNOWN'; }
        $record['source_key'] = $table === 'file_object' ? ($record['id'] ?? null) : ($record['file_key'] ?? null);
        if ($record['code'] !== null) { $record['classification'] = 'unknown'; }
        return $record;
    }

    /** @return list<array{account_key:string,driver:string,configured:bool,credential_rotated_at:string|null,config_digest:string}> */
    public function subjects(string $digestKey): array
    {
        if (strlen($digestKey) < 32) {
            throw new \InvalidArgumentException('PROVIDER_QUALIFICATION_DIGEST_KEY_INVALID');
        }
        $rows = Db::name('storage_account')->alias('a')
            ->leftJoin('storage_space s', "s.account_id=a.id AND s.status='active'")
            ->field('a.id,a.account_key,a.driver,a.credential_ciphertext,a.credential_key_version,a.credential_rotated_at,a.status,a.updated_at')
            ->fieldRaw('COUNT(s.id) AS active_space_count,MAX(s.updated_at) AS space_updated_at')
            ->group('a.id,a.account_key,a.driver,a.credential_ciphertext,a.credential_key_version,a.credential_rotated_at,a.status,a.updated_at')
            ->order('a.id')->select()->toArray();
        return array_map(static function (array $row) use ($digestKey): array {
            $driver = (string) $row['driver'];
            $configured = (string) $row['status'] === 'active'
                && (int) $row['active_space_count'] > 0
                && ($driver === 'local' || (
                    trim((string) $row['credential_ciphertext']) !== ''
                    && trim((string) $row['credential_key_version']) !== ''
                    && trim((string) $row['credential_rotated_at']) !== ''
                ));
            $payload = implode("\0", array_map(static fn(mixed $value): string => (string) $value, [
                $row['id'], $row['account_key'], $driver, $row['credential_ciphertext'],
                $row['credential_key_version'], $row['credential_rotated_at'], $row['status'],
                $row['updated_at'], $row['active_space_count'], $row['space_updated_at'],
            ]));
            $rotatedAt = null;
            if ($driver !== 'local') {
                $timestamp = strtotime((string) $row['credential_rotated_at'] . ' UTC');
                $rotatedAt = $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
            return [
                'account_key' => (string) $row['account_key'],
                'driver' => $driver,
                'configured' => $configured,
                'credential_rotated_at' => $rotatedAt,
                'config_digest' => hash_hmac('sha256', $payload, $digestKey),
            ];
        }, $rows);
    }
}
