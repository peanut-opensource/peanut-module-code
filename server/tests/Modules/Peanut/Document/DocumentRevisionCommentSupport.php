<?php

declare(strict_types=1);

namespace DocumentCommentFixture;

use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerPolicy;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerState;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantSessionSummary;
use think\facade\Db;

/** Private single-connection dialect: no claim about concurrent MySQL locks or native DDL execution. */
final class Sqlite extends \think\db\connector\Sqlite
{
    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): \PDOStatement
    {
        $sql = preg_replace('/\s+FOR UPDATE(?: SKIP LOCKED)?/i', '', $sql);
        return parent::getPDOStatement($sql, $bind, $master, $procedure);
    }
}

/** The synthetic current Identity boundary creates TenantContext only through its validated-session factory. */
final class Sessions implements TenantSessionAccess
{
    public array $revoked = [];
    public int $revision = 1;
    public function actor(int $member = 11): TenantContext
    {
        [$tenant, $account] = $this->identity($member);
        return $this->revalidateSession($tenant, $account, $member, 'synthetic-comment-' . $member, 'admin-web', 'comment-test');
    }
    public function revalidateSession(int $tenantId, int $accountId, int $memberId, string $sessionKey, string $clientKey, string $requestId): TenantContext
    {
        [$tenant, $account] = $this->identity($memberId);
        if ($tenantId !== $tenant || $accountId !== $account || $sessionKey !== 'synthetic-comment-' . $memberId
            || $clientKey !== 'admin-web' || isset($this->revoked[$memberId])) {
            throw TenantSessionAccessException::denied();
        }
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $memberId,
            $sessionKey,
            $tenantId,
            $accountId,
            $memberId,
            $clientKey,
            new \DateTimeImmutable('2026-11-01T00:00:00Z'),
            $this->revision,
            1,
        ), $requestId);
    }
    private function identity(int $member): array
    {
        return match ($member) {
            11 => [1, 101], 22 => [1, 202], 33 => [2, 303],
            default => throw TenantSessionAccessException::denied(),
        };
    }
    public function ownedSessions(TenantContext $context): array
    {
        return [];
    }
    public function revokeOwnedSession(TenantContext $context, string $sessionKey): TenantSessionSummary
    {
        throw new \LogicException('UNUSED_SYNTHETIC_SESSION_OPERATION');
    }
}

/** Explicit synthetic business-owner boundary, not a real Article/Newsroom permission claim. */
final class Owner implements DocumentOwnerPolicy
{
    public array $objects = [];
    public array $denied = [];
    /** @var array<string, \Throwable> Explicit synthetic owner errors, not native Document authorization. */
    public array $authorizationFailures = [];
    public array $calls = [];
    public function __construct(private ExecutionContextStore $contexts) {}
    public function add(int $tenant, string $id): DocumentOwnerReference
    {
        $this->objects[$tenant . ':' . $id] = ['archived' => false, 'revision' => 1];
        return new DocumentOwnerReference('example.notes', 'note', $id, 'body');
    }
    public function authorizationPermissionKeys(string $action): array
    {
        return ['example.notes.' . $action];
    }
    public function resolveOwner(TenantContext $context, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState
    {
        $root = $this->contexts->current();
        if (!$root instanceof AdminExecutionContext || $root->tenant->tenantId !== $context->tenantId
            || $root->tenant->memberId !== $context->memberId) {
            throw new \DomainException('SYNTHETIC_OWNER_NATIVE_ADMIN_REQUIRED');
        }
        $this->calls[] = ['resolve', $context->memberId, $forUpdate];
        if ($forUpdate && !Db::connect()->getPdo()->inTransaction()) {
            throw new \DomainException('SYNTHETIC_OWNER_TRANSACTION_REQUIRED');
        }
        return $this->resolvePersistedOwner($context->tenantId, $owner, $forUpdate);
    }
    public function resolvePersistedOwner(int $tenantId, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState
    {
        $object = $this->objects[$tenantId . ':' . $owner->objectId] ?? null;
        if ($owner->moduleKey !== 'example.notes' || $owner->objectType !== 'note' || $owner->slot !== 'body' || $object === null) {
            throw BusinessException::notFound('COMMENT_FIXTURE_OWNER_NOT_FOUND', 'Synthetic owner missing');
        }
        return new DocumentOwnerState($tenantId, $owner, $object['archived'], (string) $object['revision']);
    }
    public function authorize(TenantContext $context, string $action, DocumentOwnerState $owner): void
    {
        $this->calls[] = ['authorize', $context->memberId, $action];
        if (!in_array($action, ['create', 'view', 'edit', 'version.create', 'archive', 'archive.read', 'restore'], true)
            || isset($this->denied[$context->memberId . ':' . $action])) {
            throw BusinessException::forbidden('COMMENT_FIXTURE_' . strtoupper(str_replace('.', '_', $action)) . '_DENIED', 'Synthetic owner denied');
        }
        if (isset($this->authorizationFailures[$action])) {
            throw $this->authorizationFailures[$action];
        }
    }
    public function stateRevision(DocumentOwnerState $owner): string
    {
        return $owner->revision;
    }
}

/**
 * Synthetic IAM read-fence boundary only. Native comments invoke snapshot/lockAndAssert,
 * native Document proof checks and current owner decisions; this does not prove MySQL IAM locking.
 */
final class ReadFence
{
    public int $locks = 0;
    public int $revision = 1;
    public array $scopes = [];
    public ?\Closure $onLock = null;
    public function __construct(private ExecutionContextStore $contexts) {}
    public function currentDigest(TenantContext $actor, array $permissions, array $modules): string
    {
        $root = $this->contexts->current();
        if (!$root instanceof AdminExecutionContext || $root->tenant->memberId !== $actor->memberId
            || $root->tenant->tenantId !== $actor->tenantId) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_READ_SCOPE_DENIED');
        }
        return hash('sha256', json_encode([$actor->tenantId, $actor->memberId, $actor->authorizationRevision,
            $this->revision, $permissions, $modules], JSON_THROW_ON_ERROR));
    }
    public function snapshot(TenantContext $actor, array $permissions, array $modules): string
    {
        if (Db::connect()->getPdo()->inTransaction()) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_SNAPSHOT_TRANSACTION_DENIED');
        }
        $this->scopes[] = [$permissions, $modules];
        return $this->currentDigest($actor, $permissions, $modules);
    }
    public function lockAndAssert(TenantContext $actor, array $permissions, array $modules, string $expected): void
    {
        if (!Db::connect()->getPdo()->inTransaction() || !hash_equals($expected, $this->currentDigest($actor, $permissions, $modules))) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_PROOF_STALE');
        }
        ++$this->locks;
        if ($this->onLock !== null) {
            ($this->onLock)($this->locks);
        }
    }
}

final class Audit
{
    public array $events = [];
    public function appendTenantMember(mixed ...$arguments): void
    {
        $this->events[] = $arguments;
    }
}

/**
 * Translate owned native migration columns/defaults/unique/checks and Document-owned FKs.
 * External Identity/File FK targets are not reproduced by this synthetic-boundary fixture.
 */
function schema(string $path, array $names): void
{
    preg_match_all('/CREATE TABLE \x60?pa_([a-z0-9_]+)\x60? \((.*?)\n\) ENGINE/s', file_get_contents($path), $tables, PREG_SET_ORDER);
    $created = [];
    foreach ($tables as $table) {
        if (!in_array($table[1], $names, true)) {
            continue;
        }
        preg_match_all('/^  \x60?([a-z0-9_]+)\x60? (bigint|smallint|tinyint|int|varchar|char|text|mediumtext|longtext|json|datetime|mediumblob|blob)([^\n]*)/m', $table[2], $columns, PREG_SET_ORDER);
        $definitions = [];
        $hasId = false;
        foreach ($columns as $column) {
            $type = in_array($column[2], ['bigint', 'smallint', 'tinyint', 'int'], true) ? 'INTEGER'
                : (in_array($column[2], ['mediumblob', 'blob'], true) ? 'BLOB' : 'TEXT');
            $hasId = $hasId || $column[1] === 'id';
            $definition = '"' . $column[1] . '" ' . ($column[1] === 'id' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : $type);
            if ($column[1] !== 'id' && str_contains($column[3], 'NOT NULL')) {
                $definition .= ' NOT NULL';
            }
            if (preg_match('/DEFAULT (\x27[^\x27]*\x27|NULL|[0-9]+)/', $column[3], $default)) {
                $definition .= ' DEFAULT ' . $default[1];
            }
            $definitions[] = $definition;
        }
        if (!$hasId && preg_match('/PRIMARY KEY \(([^)]+)\)/', $table[2], $primary)) {
            $definitions[] = 'PRIMARY KEY (' . $primary[1] . ')';
        }
        preg_match_all('/UNIQUE KEY \x60?[a-z0-9_]+\x60? \(([^)]+)\)/', $table[2], $unique, PREG_SET_ORDER);
        foreach ($unique as $index) {
            $definitions[] = 'UNIQUE (' . $index[1] . ')';
        }
        preg_match_all('/^  CONSTRAINT ([^\n]+)/m', $table[2], $constraints, PREG_SET_ORDER);
        foreach ($constraints as $constraint) {
            $value = rtrim($constraint[1], ',');
            if (str_contains($value, ' CHECK ')) {
                $definitions[] = 'CONSTRAINT ' . str_replace('_utf8mb4', '', $value);
            } elseif (preg_match('/REFERENCES \x60pa_document(?:_[a-z0-9_]+)?\x60/', $value) === 1) {
                $definitions[] = 'CONSTRAINT ' . $value;
            }
        }
        Db::execute('CREATE TABLE pa_' . $table[1] . ' (' . implode(',', $definitions) . ')');
        foreach ($columns as $column) {
            Db::execute(
                'INSERT INTO information_schema.COLUMNS(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME) VALUES (?,?,?)',
                ['comments06', 'pa_' . $table[1], $column[1]],
            );
        }
        $created[] = $table[1];
    }
    if (count($created) !== count($names)) {
        throw new \LogicException('COMMENT_FIXTURE_NATIVE_SCHEMA_MISMATCH');
    }
}
