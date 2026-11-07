<?php

declare(strict_types=1);

namespace TaskResourceKeyCompatibilityFixture;

use Closure;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Contract\TaskSubmission;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;
use PeanutAdmin\Modules\Task\Job\Database\Schema;
use PeanutAdmin\Modules\Task\Job\Execution\LocalWorker;
use PeanutAdmin\Modules\Task\Job\Execution\TaskHandlerRegistry;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PeanutAdmin\Modules\Task\Job\Submission\TaskSubmissionRegistry;
use PeanutAdmin\Modules\Task\Service\TaskAuthorizationRouter;
use think\App;
use think\DbManager;
use think\Model;
use think\facade\Db;

/** SQL dialect only. Single-connection SQLite does not prove MySQL locking or FK behavior. */
final class Sqlite extends \think\db\connector\Sqlite
{
    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): \PDOStatement
    {
        $sql = preg_replace('/TIMESTAMPADD\(SECOND,\s*(-?\d+),\s*UTC_TIMESTAMP\(3\)\)/i', 'TASK_KEY_ADD_SECONDS($1)', $sql);
        $sql = preg_replace('/\s+FOR UPDATE(?: SKIP LOCKED)?/i', '', $sql);
        try {
            return parent::getPDOStatement($sql, $bind, $master, $procedure);
        } catch (\think\db\exception\PDOException $error) {
            // Preserve a real unique violation in the MySQL shape consumed by the unchanged Task store.
            if (str_contains($error->getMessage(), 'SQLSTATE[23000]')
                && str_contains($error->getMessage(), 'UNIQUE constraint failed:')) {
                throw new \PDOException('duplicate entry (SQLite fixture unique constraint)', 23000, $error);
            }
            throw $error;
        }
    }
}

/** Synthetic member/owner policy. No BusinessOps class or real Identity session is substituted. */
function context(string $resource, string $operation = 'execute', int $tenant = 1, ?string $targetResource = null): AuthorizedOperationContext
{
    $actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $tenant,
        'synthetic-task-key-session',
        $tenant,
        100 + $tenant,
        10 + $tenant,
        'task-key-fixture',
        new DateTimeImmutable('2026-11-07T00:00:00Z'),
        1,
        1,
    ), 'task-key-compatibility');
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
        $actor,
        $resource,
        $operation,
        [new RequestedTargetSet($targetResource ?? $resource, ['71'])],
        hash('sha256', 'synthetic-task-key-policy'),
    ));
}

final class Provider implements TaskSubmissionProvider, TaskWorkerDefinition, TaskHandler
{
    public const RESOURCE = 'custom.business-ops.serviceOrder';
    public const MODULE = 'custom.business-ops';
    public int $buildCalls = 0;
    public int $authorizationCalls = 0;
    public array $handled = [];
    public bool $allowed = true;
    public ?string $currentResource = null;
    public ?Closure $beforeFinalFence = null;

    public function __construct(
        private string $resource = self::RESOURCE,
        private string $type = 'fixture.resource-expiry',
        private string $op = 'execute',
        private string $handler = 'fixture.resource-expiry.handle',
    ) {}

    public function taskType(): string
    {
        return $this->type;
    }
    public function resourceKey(): string
    {
        return $this->resource;
    }
    public function operation(): string
    {
        return $this->op;
    }
    public function ownerModuleKey(): string
    {
        return self::MODULE;
    }
    public function key(): string
    {
        return $this->handler;
    }
    public function handlers(): array
    {
        return [$this];
    }

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        ++$this->buildCalls;
        if ($input !== ['order_id' => 71]) {
            throw new \InvalidArgumentException('SYNTHETIC_TASK_INPUT_INVALID');
        }
        return new TaskSubmission($this->handler, $input, 1);
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        ++$this->authorizationCalls;
        $targets = array_map(static fn(RequestedTargetSet $set): array => $set->toArray(), $envelope->requestedTargets);
        if (!$this->allowed || $envelope->tenantId !== 1 || $envelope->accountId !== 101 || $envelope->memberId !== 11
            || $envelope->resourceKey !== $this->resource || $envelope->operation !== $this->op
            || $targets !== [(new RequestedTargetSet($this->resource, ['71']))->toArray()]) {
            throw new AuthException('SYNTHETIC_TASK_SCOPE_DENIED', 403);
        }
        // A case-different current authorization is negative input to the real JobAuthorizationFence.
        return context($this->currentResource ?? $this->resource, $this->op, targetResource: $this->resource);
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        if ($this->beforeFinalFence !== null) {
            ($this->beforeFinalFence)($execution);
        }
        $execution->assertLeaseOwned();
        $this->handled[] = [
            'resource' => $context->resourceKey,
            'targets' => array_map(static fn(RequestedTargetSet $set): array => $set->toArray(), $context->targets),
            'job_key' => $execution->jobKey, 'tenant_id' => $execution->tenantId, 'payload' => $execution->payload,
        ];
    }
}

/** Real Task and entitlement services; identity/owner inputs and local SQL dialect are synthetic. */
final class Fixture
{
    public readonly TaskJobStore $store;
    public readonly TrustedEnvelopeCodec $codec;
    public readonly TenantEntitlementService $entitlements;
    public readonly App $app;
    public readonly DbManager $db;

    public function __construct()
    {
        $server = dirname(__DIR__, 4);
        $directory = dirname($server) . '/.local/007-task-resource-key-20261107/runtime-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('TASK_KEY_FIXTURE_DIRECTORY_FAILED');
        }
        $this->app = new App($directory . '/');
        \think\Container::setInstance($this->app);
        $this->db = new DbManager();
        $this->db->setConfig(['default' => 'taskkey007', 'connections' => ['taskkey007' => [
            'type' => Sqlite::class, 'database' => ':memory:', 'prefix' => 'pa_',
            'builder' => \think\db\builder\Sqlite::class, 'fields_cache' => false,
        ]]]);
        $this->app->instance(DbManager::class, $this->db);
        $db = $this->db;
        Model::maker(static fn(Model $model) => $model->setOption('db', $db));
        $db->query('SELECT 1');
        $pdo = $db->connect()->getPdo();
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => gmdate('Y-m-d H:i:s') . '.000', 1);
        $pdo->sqliteCreateFunction('TASK_KEY_ADD_SECONDS', static fn(int $seconds): string => gmdate('Y-m-d H:i:s', time() + $seconds) . '.000', 1);
        $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'taskkey007', 0);
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT,TABLE_NAME TEXT,COLUMN_NAME TEXT)');
        self::schema();
        // Private synthetic entitlement rows; the unchanged native entitlement service reads them.
        Db::execute('CREATE TABLE pa_plan (id INTEGER PRIMARY KEY,code TEXT,name TEXT,revision INTEGER)');
        Db::execute('CREATE TABLE pa_tenant_license (id INTEGER PRIMARY KEY,tenant_id INTEGER,plan_id INTEGER,status TEXT,effective_at TEXT,expires_at TEXT,revision INTEGER,created_at TEXT,updated_at TEXT)');
        Db::execute('CREATE TABLE pa_plan_module (plan_id INTEGER,module_key TEXT)');
        Db::execute('CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY,status TEXT)');
        Db::name('plan')->insert(['id' => 1,'code' => 'synthetic','name' => 'Synthetic task test','revision' => 1]);
        Db::name('tenant_license')->insert(['id' => 1,'tenant_id' => 1,'plan_id' => 1,'status' => 'active',
            'effective_at' => '2020-01-01 00:00:00.000','expires_at' => null,'revision' => 1]);
        Db::name('plan_module')->insert(['plan_id' => 1,'module_key' => Provider::MODULE]);
        Db::name('module_installation')->insert(['module_key' => Provider::MODULE,'status' => 'active']);
        $registry = new CompiledModuleRegistry([
            ManifestDocument::fromObject('/synthetic/task-resource-key', (object) [
                'key' => Provider::MODULE, 'tenant' => ['enableable' => true],
            ]),
        ], [], [], [], 'synthetic-task-key');
        $this->entitlements = new TenantEntitlementService($registry);
        $this->store = new TaskJobStore();
        $this->codec = new TrustedEnvelopeCodec(str_repeat('task-key-fixture-', 3));
    }

    public function publisher(\PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog $catalog, Provider ...$providers): TrustedJobPublisher
    {
        return new TrustedJobPublisher($this->store, new TaskSubmissionRegistry($providers), $this->codec, $catalog, $this->entitlements);
    }

    public function worker(Provider $provider, ?string $jobKey = null, int $tenant = 1): LocalWorker
    {
        return new LocalWorker(
            $tenant,
            'task-key-worker',
            $this->store,
            new TaskHandlerRegistry($provider->handlers()),
            new JobHandlerAdapter($this->codec, new TaskAuthorizationRouter([$provider])),
            // Controlled host lifecycle only; no fabricated System/Admin context. Worker and fence are real.
            static function ($context, $claim, Closure $handle): void {
                $handle();
            },
            jobKey: $jobKey,
        );
    }

    public function row(string $jobKey): array
    {
        return Db::name('task_job')->where('job_key', $jobKey)->find() ?? throw new \RuntimeException('TASK_KEY_ROW_MISSING');
    }

    public function state(): array
    {
        return array_map(
            static fn(string $table): array => Db::name($table)->order('id')->select()->toArray(),
            ['task_job', 'task_job_attempt', 'task_job_event'],
        );
    }

    private static function schema(): void
    {
        foreach (Schema::tableNames() as $table) {
            $source = Schema::createSql($table);
            preg_match_all('/^  \x60([a-z0-9_]+)\x60 ([A-Z]+)([^\n]*)/m', $source, $columns, PREG_SET_ORDER);
            if ($columns === []) {
                throw new \RuntimeException('TASK_KEY_NATIVE_SCHEMA_EMPTY');
            }
            $sql = [];
            foreach ($columns as $column) {
                $definition = '"' . $column[1] . '" ' . ($column[1] === 'id' ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
                    : (in_array($column[2], ['BIGINT','SMALLINT','INT'], true) ? 'INTEGER' : 'TEXT'));
                if ($column[1] !== 'id' && str_contains($column[3], 'NOT NULL')) {
                    $definition .= ' NOT NULL';
                }
                if (preg_match('/DEFAULT (\x27[^\x27]*\x27|NULL|-?\d+)/', $column[3], $default)) {
                    $definition .= ' DEFAULT ' . $default[1];
                }
                $sql[] = $definition;
            }
            preg_match_all('/UNIQUE KEY \x60[^\x60]+\x60 \(([^)]+)\)/', $source, $unique, PREG_SET_ORDER);
            foreach ($unique as $index) {
                $sql[] = 'UNIQUE (' . $index[1] . ')';
            }
            Db::execute('CREATE TABLE "' . $table . '" (' . implode(',', $sql) . ')');
            foreach ($columns as $column) {
                Db::execute(
                    'INSERT INTO information_schema.COLUMNS(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME) VALUES (?,?,?)',
                    ['taskkey007', $table, $column[1]],
                );
            }
        }
    }
}
