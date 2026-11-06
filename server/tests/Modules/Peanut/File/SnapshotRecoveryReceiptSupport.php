<?php

declare(strict_types=1);

namespace SnapshotRecoveryReceiptFixture;

use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;

/** Deployment directory boundary; no Identity database or session is contacted. */
final class Directory
{
    public function tenantStatus(int $tenantId): ?string { return $tenantId === 1 ? 'active' : null; }
}

/** Synthetic live session/permission boundary; storage, references and Document remain native. */
final class FileAuthorization
{
    public bool $allowed = true;

    public function authorize(TenantContext $context, array $object, string $operation, ?int $documentId = null): array
    {
        $this->requireManagementPermission($context, $operation);
        if ((int) $object['tenant_id'] !== $context->tenantId || $object['status'] !== 'ready') {
            throw BusinessException::forbidden('FILE_ACCESS_DENIED', 'Synthetic object access denied');
        }
        return ['authorization_revision' => 'synthetic-file-grant', 'context' => $context];
    }

    public function authorizeDocumentReference(TenantContext $context, array $object, int $documentId): array
    {
        return $this->authorize($context, $object, 'preview', $documentId);
    }

    public function requireManagementPermission(TenantContext $context, string $permission): void
    {
        if (!$this->allowed || $context->tenantId !== 1 || $context->memberId !== 11) {
            throw BusinessException::forbidden('SYNTHETIC_FILE_PERMISSION_REVOKED', 'Synthetic live grant revoked');
        }
    }
}

/** Audit sink only; never supplies recovery state. */
final class Audit
{
    public function appendTenantMember(mixed ...$arguments): void {}
}

/** Translate only migration columns/defaults/unique keys to the explicitly isolated SQLite connection. */
function schema(string $migration, array $names): void
{
    preg_match_all('/CREATE TABLE `pa_([^`]+)` \((.*?)\n\) ENGINE/s', file_get_contents($migration), $tables, PREG_SET_ORDER);
    $created = [];
    foreach ($tables as $table) {
        if (!in_array($table[1], $names, true)) continue;
        preg_match_all('/^  `([^`]+)` ([a-z]+)([^\n]*)/m', $table[2], $columns, PREG_SET_ORDER);
        $sql = [];
        foreach ($columns as $column) {
            $type = in_array($column[2], ['bigint', 'tinyint', 'int'], true) ? 'INTEGER' : 'TEXT';
            $definition = '`' . $column[1] . '` ' . ($column[1] === 'id' ? 'INTEGER PRIMARY KEY' : $type);
            if (preg_match('/DEFAULT (\x27[^\x27]*\x27|NULL)/', $column[3], $default)) $definition .= ' DEFAULT ' . $default[1];
            if ($table[1] === 'file_immutable_snapshot' && $column[1] === 'file_key') $definition .= ' PRIMARY KEY';
            $sql[] = $definition;
        }
        preg_match_all('/UNIQUE KEY `[^`]+` \(([^)]+)\)/', $table[2], $unique, PREG_SET_ORDER);
        foreach ($unique as $index) $sql[] = 'UNIQUE (' . $index[1] . ')';
        Db::execute('CREATE TABLE ' . $table[1] . ' (' . implode(',', $sql) . ')');
        $created[] = $table[1];
    }
    if (count($created) !== count($names)) throw new \LogicException('Migration table selection mismatch');
}
