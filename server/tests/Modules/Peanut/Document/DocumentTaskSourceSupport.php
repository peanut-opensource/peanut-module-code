<?php

declare(strict_types=1);

namespace SourceGrantFixture;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantSessionSummary;
use PeanutAdmin\Modules\Task\Contract\{JobExecution,TaskHandler,TaskSubmission,TaskSubmissionProvider,TaskWorkerDefinition};
use think\facade\Db;

/** SQLite dialect compatibility only: all native ORM, task lease and grant state transitions are retained. */
final class Sqlite extends \think\db\connector\Sqlite
{
    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): \PDOStatement
    {
        $sql = preg_replace('/TIMESTAMPADD\(SECOND,\s*(-?\d+),\s*UTC_TIMESTAMP\(3\)\)/i', 'SOURCE_ADD_SECONDS($1)', $sql);
        $sql = preg_replace('/\s+FOR UPDATE(?: SKIP LOCKED)?/i', '', $sql);
        return parent::getPDOStatement($sql, $bind, $master, $procedure);
    }
}

/** Explicit synthetic Identity directory/entitlement boundaries; neither confers Document access. */
final class Directory
{
    public function tenantStatus(int $id): ?string
    {
        return in_array($id, [1,2], true) ? 'active' : null;
    }
}
final class Entitlements
{
    public function moduleKeys(int $id): array
    {
        return ['peanut.document','peanut.file','peanut.task','peanut.article','custom.ai-assistant'];
    }
}
final class Audit
{
    public function appendTenantMember(mixed ...$arguments): void {} public function appendTenantSystem(mixed ...$arguments): void {}
}

/** Test-owned validated sessions. Production TenantContext is created only by its public validated-session factory. */
final class Sessions implements TenantSessionAccess
{
    public array $revoked = [];
    public int $revision = 1;
    public int $calls = 0;
    public function actor(int $tenant, string $request = 'source-007'): TenantContext
    {
        return $this->revalidateSession($tenant, 100 + $tenant, 10 + $tenant, 'synthetic-source-session-' . $tenant, 'admin-web', $request);
    }
    public function revalidateSession(int $tenantId, int $accountId, int $memberId, string $sessionKey, string $clientKey, string $requestId): TenantContext
    {
        ++$this->calls;
        if (!in_array($tenantId, [1,2], true) || $accountId !== 100 + $tenantId || $memberId !== 10 + $tenantId
            || $sessionKey !== 'synthetic-source-session-' . $tenantId || $clientKey !== 'admin-web' || ($this->revoked[$tenantId] ?? false)) {
            throw new \DomainException('SYNTHETIC_SESSION_REVOKED');
        }
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $tenantId,
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
    public function ownedSessions(TenantContext $context): array
    {
        return [];
    }
    public function revokeOwnedSession(TenantContext $context, string $sessionKey): TenantSessionSummary
    {
        throw new \LogicException('unused');
    }
}

/** Synthetic consumer authorization only; publisher/envelope/router/LocalWorker/runtime/System root remain production. */
final class Worker implements TaskSubmissionProvider, TaskWorkerDefinition, TaskHandler
{
    public \Closure $handler;
    public ?\Throwable $error = null;
    public bool $allowed = true;
    public int $entered = 0;
    public function __construct(private Sessions $sessions)
    {
        $this->handler = static fn() => null;
    }
    public function taskType(): string
    {
        return 'custom.ai-assistant.generate';
    }
    public function key(): string
    {
        return $this->taskType();
    }
    public function ownerModuleKey(): string
    {
        return 'custom.ai-assistant';
    }
    public function resourceKey(): string
    {
        return 'custom.ai-assistant.task';
    }
    public function operation(): string
    {
        return 'use';
    }
    public function handlers(): array
    {
        return [$this];
    }
    public function context(TenantContext $actor): AuthorizedOperationContext
    {
        if (!$this->allowed) {
            throw new \DomainException('SYNTHETIC_TASK_SCOPE_REVOKED');
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $actor,
            $this->resourceKey(),
            $this->operation(),
            [new RequestedTargetSet($this->resourceKey(), [(string) $actor->tenantId])],
            hash('sha256', 'scope:' . $actor->authorizationRevision),
        ));
    }
    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        if ($input !== ['task_id' => $context->tenantContext->tenantId]) {
            throw new \DomainException('SYNTHETIC_TASK_INPUT_INVALID');
        }
        return new TaskSubmission($this->key(), $input, 1);
    }
    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        $actor = $this->sessions->revalidateSession(
            $envelope->tenantId,
            $envelope->accountId,
            $envelope->memberId,
            'synthetic-source-session-' . $envelope->tenantId,
            'admin-web',
            $envelope->traceId,
        );
        return $this->context($actor);
    }
    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        ++$this->entered;
        try {
            ($this->handler)($context, $execution);
        } catch (\Throwable $error) {
            $this->error = $error;
            throw $error;
        }
    }
}

/** Derive SQLite columns/defaults/unique keys from owned native migrations, not a parallel grant schema. */
function schema(string $source, array $names): void
{
    preg_match_all('/CREATE TABLE \x60?pa_([a-z0-9_]+)\x60? \((.*?)\n\) ENGINE/s', file_get_contents($source), $tables, PREG_SET_ORDER);
    $created = [];
    foreach ($tables as $table) {
        if (!in_array($table[1], $names, true)) {
            continue;
        }
        preg_match_all('/^  \x60?([a-z0-9_]+)\x60? (bigint|smallint|tinyint|int|varchar|char|text|mediumtext|longtext|json|datetime|mediumblob|blob|decimal)([^\n]*)/m', $table[2], $columns, PREG_SET_ORDER);
        $sql = [];
        foreach ($columns as $column) {
            $type = in_array($column[2], ['bigint','smallint','tinyint','int'], true) ? 'INTEGER' : 'TEXT';
            $definition = '"' . $column[1] . '" ' . ($column[1] === 'id' ? 'INTEGER PRIMARY KEY' : $type);
            if (preg_match('/DEFAULT (\x27[^\x27]*\x27|NULL)/', $column[3], $default)) {
                $definition .= ' DEFAULT ' . $default[1];
            }
            if ($table[1] === 'file_immutable_snapshot' && $column[1] === 'file_key') {
                $definition .= ' PRIMARY KEY';
            }
            $sql[] = $definition;
        }
        preg_match_all('/UNIQUE KEY \x60?[a-z0-9_]+\x60? \(([^)]+)\)/', $table[2], $unique, PREG_SET_ORDER);
        foreach ($unique as $index) {
            $sql[] = 'UNIQUE (' . $index[1] . ')';
        }
        Db::execute('CREATE TABLE pa_' . $table[1] . ' (' . implode(',', $sql) . ')');
        foreach ($columns as $column) {
            Db::execute('INSERT INTO information_schema.COLUMNS(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME) VALUES (?,?,?)', ['source007','pa_' . $table[1],$column[1]]);
        }
        $created[] = $table[1];
    }
    if (count($created) !== count($names)) {
        throw new \LogicException('SOURCE_FIXTURE_SCHEMA_MISMATCH ' . json_encode([$names,$created]));
    }
}

/** Synthetic IAM read-digest boundary; real owner policy and source/Task authorization decisions remain exercised. */
final class ReadFence
{
    public function currentDigest(TenantContext $context, array $permissions, array $modules): string
    {
        return hash('sha256', json_encode([$context->tenantId,$context->memberId,$context->authorizationRevision,$permissions,$modules], JSON_THROW_ON_ERROR));
    }
}

/** Synthetic data-policy decisions. The native constraint applier and every actual AI tenant/object query remain in use. */
final class DataEngine
{
    public array $denied = [];
    public function queryConstraint(TenantContext $context, string $resource, string $operation): \PeanutAdmin\DataPermission\Constraint\QueryConstraint
    {
        return isset($this->denied[$resource]) ? new \PeanutAdmin\DataPermission\Constraint\AlwaysFalse() : new \PeanutAdmin\DataPermission\Constraint\AlwaysTrue();
    }
    public function decideCreate(TenantContext $context, string $resource, string $operation, mixed $targets): object
    {
        return (object) ['allowed' => !isset($this->denied[$resource])];
    }
}

/** Observability wrapper only: every real AI reauthorization and handler call is delegated unchanged. */
final class ObservedDefinition implements TaskWorkerDefinition
{
    public ?\Throwable $error = null;
    public int $entered = 0;
    public function __construct(private TaskWorkerDefinition $inner) {}
    public function ownerModuleKey(): string
    {
        return $this->inner->ownerModuleKey();
    }
    public function resourceKey(): string
    {
        return $this->inner->resourceKey();
    }
    public function operation(): string
    {
        return $this->inner->operation();
    }
    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        return $this->inner->reauthorize($envelope);
    }
    public function handlers(): array
    {
        return array_map(fn(TaskHandler $inner): TaskHandler => new class ($inner, $this) implements TaskHandler {
            public function __construct(private TaskHandler $inner, private ObservedDefinition $observer) {}
            public function key(): string
            {
                return $this->inner->key();
            }
            public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
            {
                ++$this->observer->entered;
                try {
                    $this->inner->handle($context, $execution);
                } catch (\Throwable $error) {
                    $this->observer->error = $error;
                    throw $error;
                }
            }
        }, $this->inner->handlers());
    }
}

/** Extract the exact four AI owned-table definitions from its compact native migration. */
function aiSchema(string $source): void
{
    $code = file_get_contents($source);
    if (preg_match('/\$common="([^"]+)";/', $code, $common) !== 1) {
        throw new \LogicException('AI_SCHEMA_COMMON_MISSING');
    }
    preg_match_all('/\$this->execute\("(CREATE TABLE [^"]+)"\);/', $code, $statements);
    if (count($statements[1]) !== 4) {
        throw new \LogicException('AI_SCHEMA_TABLE_COUNT');
    }
    foreach ($statements[1] as $statement) {
        $statement = str_replace('$common', $common[1], $statement);
        if (preg_match('/CREATE TABLE (pa_[a-z_]+) \((.*)\) ENGINE/s', $statement, $parts) !== 1) {
            throw new \LogicException('AI_SCHEMA_INVALID');
        }
        $definitions = [];
        $part = '';
        $depth = 0;
        foreach (str_split($parts[2]) as $char) {
            if ($char === ',' && $depth === 0) {
                $definitions[] = $part;
                $part = '';
                continue;
            }
            if ($char === '(') {
                ++$depth;
            }
            if ($char === ')') {
                --$depth;
            }
            $part .= $char;
        }
        $definitions[] = $part;
        $sql = [];
        foreach ($definitions as $definition) {
            if (preg_match('/^([a-z_]+) (bigint|varchar|char|json)(.*)$/', trim($definition), $column)) {
                $type = $column[2] === 'bigint' ? 'INTEGER' : 'TEXT';
                $value = $column[1] . ' ' . ($column[1] === 'id' ? 'INTEGER PRIMARY KEY' : $type);
                if (preg_match('/DEFAULT (NULL|[0-9]+)/', $column[3], $default)) {
                    $value .= ' DEFAULT ' . $default[1];
                }
                $sql[] = $value;
            } elseif (preg_match('/^UNIQUE KEY [a-z_]+\((.+)\)$/', trim($definition), $unique)) {
                $sql[] = 'UNIQUE (' . $unique[1] . ')';
            }
        }
        Db::execute('CREATE TABLE ' . $parts[1] . ' (' . implode(',', $sql) . ')');
    }
}
