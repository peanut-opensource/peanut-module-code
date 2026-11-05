<?php

declare(strict_types=1);

use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\ColumnDefinition;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\DataProvider;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\DataProviderRegistry;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\ExportBatch;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\RowIssue;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\SchemaDefinition;
use PeanutAdmin\Modules\ImportExport\Engine\Database\Schema;
use PeanutAdmin\Modules\ImportExport\Engine\Execution\CsvOperationRunner;
use PeanutAdmin\Modules\ImportExport\Engine\File\FileMediaGateway;
use PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use think\facade\Db;

$root = dirname(__DIR__, 4);
require_once defined('PHPUNIT_COMPOSER_INSTALL') ? PHPUNIT_COMPOSER_INSTALL : $root . '/vendor/autoload.php';
require_once $root . '/tests/Support/ThinkPhpTestConnection.php';
require_once $root . '/tests/Support/RegisteredMysqlTestResource.php';
require_once $root . '/tests/Support/IdentityMysqlFixture.php';

function check(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function problem(string $code, callable $operation, string $label): void
{
    try {
        $operation();
    } catch (ImportExportException $exception) {
        check($code, $exception->problemCode, $label);
        return;
    }
    throw new RuntimeException($label . ': expected ' . $code);
}
function runtimeProblem(string $code, callable $operation, string $label): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        check($code, $exception->getMessage(), $label);
        return;
    }
    throw new RuntimeException($label . ': expected ' . $code);
}
function context(int $tenantId, int $memberId, string $operation): AuthorizedOperationContext
{
    $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $tenantId,
        'session-' . $tenantId,
        $tenantId,
        $tenantId + 10,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2026-07-24T00:00:00Z'),
        1,
    ), 'request-' . $tenantId);
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($tenant, ImportExportService::RESOURCE_KEY, $operation, [], 'basis-' . $tenantId));
}

function execution(string $jobKey, int $attempt): JobExecution
{
    $lease = static function (): void {};
    return new JobExecution($jobKey, 101, $attempt, [], $lease, $lease);
}

final class HarnessProvider implements DataProvider
{
    /** @var list<array<string, string|null>> */
    public array $imported = [];
    public ?Closure $duringImport = null;
    public function key(): string
    {
        return 'test.contacts';
    }
    public function schema(): SchemaDefinition
    {
        return new SchemaDefinition('contacts.v1', [new ColumnDefinition('name', 'Name', requiredOnImport: true, maxBytes: 64), new ColumnDefinition('email', 'Email', maxBytes: 120), new ColumnDefinition('formula_header', '=Formula', importable: false)]);
    }
    public function validateImport(AuthorizedOperationContext $context, array $row): array
    {
        return isset($row['email']) && $row['email'] !== null && !str_contains($row['email'], '@') ? [new RowIssue('CONTACT_EMAIL_INVALID', 'email')] : [];
    }
    public function importRow(AuthorizedOperationContext $context, array $row, string $idempotencyKey): void
    {
        if ($this->duringImport !== null) {
            ($this->duringImport)();
        } $this->imported[] = $row + ['idempotency' => $idempotencyKey];
    }
    public function exportBatch(AuthorizedOperationContext $context, ?string $cursor, int $limit): ExportBatch
    {
        return $cursor === null ? new ExportBatch([['name' => "\xEF\xBB\xBF \t=Alice", 'email' => 'alice@example.test', 'formula_header' => "\t@payload"], ['name' => "\r-Bob", 'email' => null, 'formula_header' => '+formula']], null) : throw new RuntimeException('unexpected cursor');
    }
}

final class HarnessFiles implements FileMediaGateway
{
    /** @var array<string, string> */ public array $inputs = [];
    /** @var array<string, string> */ public array $outputs = [];
    public function openCsvInput(AuthorizedOperationContext $context, string $fileKey)
    {
        if (!isset($this->inputs[$fileKey])) {
            throw ImportExportException::fileUnavailable();
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->inputs[$fileKey]);
        rewind($stream);
        return $stream;
    }
    public function storePrivateCsv(AuthorizedOperationContext $context, string $operationKey, string $purpose, string $filename, $stream): string
    {
        $content = stream_get_contents($stream);
        if (!is_string($content)) {
            throw ImportExportException::fileUnavailable();
        }
        $key = 'file_' . substr(hash('sha256', $operationKey . '|' . $purpose), 0, 32);
        $this->outputs[$key] = $content;
        return $key;
    }
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
$ownedDatabase = null;
$run = static function () use ($database, &$ownedDatabase): void {
    [$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);
    $ownedDatabase = [$pdo, $createdDatabase];
    IdentityMysqlFixture::install($pdo);
    $pdo->exec("INSERT INTO pa_account(id,display_name,status,created_at,updated_at) VALUES (111,'A','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(212,'B','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant(id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES (101,'iox-a','A','A','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'iox-b','B','B','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant_member(id,tenant_id,account_id,display_name,status,joined_at,created_at,updated_at) VALUES (501,101,111,'A','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(502,202,212,'B','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    foreach (Schema::tableNames() as $table) {
        $pdo->exec(Schema::createSql($table));
    }
    foreach (Schema::tableNames() as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'tenant_id'")->fetchColumn();
        check(1, (int) $count, "{$table} retains tenant scope");
    }

    $connection = ThinkPhpTestConnection::fromPdo($pdo);
    $maintenanceContexts = new \app\common\execution\ExecutionContextStore();
    $maintenanceCurrent = new \app\common\execution\CurrentExecutionContext($maintenanceContexts);
    $maintenanceAudit = new \app\common\services\audit\AuditContractHost($maintenanceCurrent);
    $maintenance = static fn(callable $callback): mixed => $maintenanceContexts->run(
        new \app\common\execution\InstanceExecutionContext('console.import-export:expire', 'import-retention-fixture'),
        $callback,
    );
    $repository = new ImportExportStore(
        $maintenanceCurrent,
        $maintenanceAudit,
    );
    $provider = new HarnessProvider();
    $files = new HarnessFiles();
    $audit = new AuditService();
    $registry = new DataProviderRegistry([$provider]);
    $runner = new CsvOperationRunner($repository, $registry, $files, $audit);
    $atomic = static fn(callable $operation): mixed => Db::transaction($operation);
    $create101 = context(101, 501, 'create');
    $read101 = context(101, 501, 'read');
    ImportExportService::assertOperation($create101, 'create');
    problem('IMPORT_EXPORT_PERMISSION_DENIED', fn() => ImportExportService::assertOperation($read101, 'create'), 'permission operation fails closed');

    $fileKey = 'file_' . str_repeat('a', 32);
    $files->inputs[$fileKey] = "Name,Email\r\nAlice,alice@example.test\r\nBob,invalid\r\n,empty@example.test\r\n";
    $mapping = $provider->schema()->validateImportMapping(['Name' => 'name', 'Email' => 'email']);
    $import = $atomic(fn() => $repository->create(101, 501, 'iox_' . str_repeat('1', 32), $provider->key(), 'import', $fileKey, 'contacts.v1', $mapping, hash('sha256', 'idem-import'), hash('sha256', 'request-import'), 7));
    $replay = $atomic(fn() => $repository->create(101, 501, 'iox_' . str_repeat('2', 32), $provider->key(), 'import', $fileKey, 'contacts.v1', $mapping, hash('sha256', 'idem-import'), hash('sha256', 'request-import'), 7));
    check($import->operationKey, $replay->operationKey, 'idempotency replay');
    problem('IMPORT_EXPORT_IDEMPOTENCY_CONFLICT', fn() => $atomic(fn() => $repository->create(101, 501, 'iox_' . str_repeat('3', 32), $provider->key(), 'import', $fileKey, 'contacts.v1', $mapping, hash('sha256', 'idem-import'), hash('sha256', 'changed'), 7)), 'idempotency payload conflict');
    problem('IMPORT_EXPORT_NOT_FOUND', fn() => $repository->get(202, $import->operationKey), 'cross tenant detail indistinguishable');
    $import = $atomic(fn() => $repository->attachJob(101, $import->operationKey, 'job_' . str_repeat('a', 32)));
    $import = $runner->run($create101, $import->operationKey, execution($import->taskJobKey ?? '', 1));
    check('succeeded', $import->status, 'import completion');
    check(3, $import->processedRows, 'import progress');
    check(1, $import->acceptedRows, 'accepted rows');
    check(2, $import->rejectedRows, 'rejected rows');
    check(1, count($provider->imported), 'provider called only for valid row');
    check(2, count($repository->rowIssues(101, $import->id)), 'redacted row errors');
    if ($import->errorFileKey === null || str_contains($files->outputs[$import->errorFileKey], 'invalid') || str_contains($files->outputs[$import->errorFileKey], 'empty@example')) {
        throw new RuntimeException('Error report leaked rejected values.');
    }
    problem('IMPORT_EXPORT_STATE_CONFLICT', fn() => $runner->run($create101, $import->operationKey, execution($import->taskJobKey ?? '', 2)), 'terminal operation cannot be reclaimed');

    $export = $atomic(fn() => $repository->create(101, 501, 'iox_' . str_repeat('4', 32), $provider->key(), 'export', null, 'contacts.v1', [], hash('sha256', 'idem-export'), hash('sha256', 'request-export'), 7));
    $export = $atomic(fn() => $repository->attachJob(101, $export->operationKey, 'job_' . str_repeat('b', 32)));
    $export = $runner->run($create101, $export->operationKey, execution($export->taskJobKey ?? '', 1));
    check('succeeded', $export->status, 'export completion');
    check(2, $export->processedRows, 'export rows');
    if ($export->resultFileKey === null) {
        throw new RuntimeException('Export result missing.');
    }
    $csv = $files->outputs[$export->resultFileKey];
    foreach (["'=Formula", "'\xEF\xBB\xBF \t=Alice", "'\t@payload", "'\r-Bob", "'+formula"] as $safeText) {
        if (!str_contains($csv, $safeText)) {
            throw new RuntimeException('CSV formula was not neutralized: ' . bin2hex($safeText));
        }
    }
    $events = array_map(static function (array $row): string {
        $metadata = json_decode((string) $row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
        $keys = array_keys($metadata);
        sort($keys);
        return (string) $row['event_type'] . ':' . implode(',', $keys);
    }, $pdo->query('SELECT event_type, metadata_json FROM pa_tenant_audit_event ORDER BY id')->fetchAll());
    check(['tenant.import_export.started:attempt,direction,provider_key,revision', 'tenant.import_export.progress:accepted_rows,direction,processed_rows,provider_key,rejected_rows,revision', 'tenant.import_export.succeeded:accepted_rows,direction,processed_rows,provider_key,rejected_rows,revision', 'tenant.import_export.started:attempt,direction,provider_key,revision', 'tenant.import_export.succeeded:accepted_rows,direction,processed_rows,provider_key,rejected_rows,revision'], $events, 'redacted lifecycle audit');

    problem('IMPORT_EXPORT_SCHEMA_MISMATCH', fn() => $provider->schema()->normalizeImportRow(["\xC3\x28"], ['Name'], ['Name' => 'name']), 'invalid UTF-8 import cell');
    problem('IMPORT_EXPORT_SCHEMA_MISMATCH', fn() => $provider->schema()->normalizeImportRow(['Alice', "\xC3\x28"], ['Name', 'Ignored'], ['Name' => 'name']), 'invalid UTF-8 unmapped import cell');
    problem('IMPORT_EXPORT_SCHEMA_MISMATCH', fn() => $provider->schema()->exportValues(['name' => "\xC3\x28", 'email' => null, 'formula_header' => 'safe']), 'invalid UTF-8 export text');
    problem('IMPORT_EXPORT_INVALID', fn() => new ColumnDefinition('invalid_heading', "\xC3\x28"), 'invalid UTF-8 schema heading');

    $cancel = $atomic(fn() => $repository->create(101, 501, 'iox_' . str_repeat('5', 32), $provider->key(), 'export', null, 'contacts.v1', [], hash('sha256', 'idem-cancel'), hash('sha256', 'request-cancel'), 1));
    $cancel = $atomic(fn() => $repository->requestCancel(101, $cancel->operationKey, $cancel->revision));
    check('cancelled', $cancel->status, 'queued cancellation');

    $racingFileKey = 'file_' . str_repeat('b', 32);
    $files->inputs[$racingFileKey] = "Name,Email\r\nRace,race@example.test\r\n";
    $racing = $atomic(fn() => $repository->create(101, 501, 'iox_' . str_repeat('6', 32), $provider->key(), 'import', $racingFileKey, 'contacts.v1', $mapping, hash('sha256', 'idem-racing'), hash('sha256', 'request-racing'), 1));
    $racing = $atomic(fn() => $repository->attachJob(101, $racing->operationKey, 'job_' . str_repeat('c', 32)));
    $provider->duringImport = static function () use ($atomic, $repository, &$racing): void {
        $current = $repository->get(101, $racing->operationKey);
        $atomic(fn() => $repository->requestCancel(101, $racing->operationKey, $current->revision));
    };
    $racing = $runner->run($create101, $racing->operationKey, execution($racing->taskJobKey ?? '', 1));
    $provider->duringImport = null;
    check('cancelled', $racing->status, 'provider/progress cancellation race settles without runner failure');
    check(1, $racing->processedRows, 'cancel race progress checkpoint');

    $finishRace = $atomic(fn() => $repository->create(101, 501, 'iox_' . str_repeat('7', 32), $provider->key(), 'export', null, 'contacts.v1', [], hash('sha256', 'idem-finish-race'), hash('sha256', 'request-finish-race'), 1));
    $finishRace = $atomic(fn() => $repository->attachJob(101, $finishRace->operationKey, 'job_' . str_repeat('e', 32)));
    $finishRace = $atomic(fn() => $repository->beginAttempt(101, $finishRace->operationKey, $finishRace->taskJobKey ?? '', 1));
    $finishRace = $atomic(fn() => $repository->requestCancel(101, $finishRace->operationKey, $finishRace->revision));
    $finishRace = $atomic(fn() => $repository->finish(101, $finishRace->id, $finishRace->taskJobKey ?? '', 1, 'succeeded', 'file_' . str_repeat('f', 32), null, 0));
    check('cancelled', $finishRace->status, 'finish/cancel race prefers cancellation');
    check(null, $finishRace->resultFileKey, 'cancelled finish publishes no result');
    $pdo->exec("UPDATE pa_import_export_operation SET retention_until = TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(3)) WHERE operation_key = " . $pdo->quote($cancel->operationKey));
    check(1, $maintenance(fn() => $repository->expireDue()), 'retention expiry');
    check('expired', $repository->get(101, $cancel->operationKey)->status, 'expired terminal state');
    $pdo->exec('ALTER TABLE pa_import_export_operation CHANGE tenant_id incompatible_tenant_id BIGINT UNSIGNED NOT NULL');
    try {
        runtimeProblem(
            'TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH',
            fn() => (new ImportExportStore($maintenanceCurrent, $maintenanceAudit))->get(101, $cancel->operationKey),
            'repository rejects schema without tenant column',
        );
    } finally {
        $pdo->exec('ALTER TABLE pa_import_export_operation CHANGE incompatible_tenant_id tenant_id BIGINT UNSIGNED NOT NULL');
    }
    check('expired', $repository->get(101, $cancel->operationKey)->status, 'schema rejection leaves expired record intact');

};

try {
    $run();
} finally {
    if ($ownedDatabase !== null) {
        [$pdo, $createdDatabase] = $ownedDatabase;
        RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
        $ownedDatabase = null;
    }
}
fwrite(STDOUT, "import-export multi-tenant PASS (migration, permission, idempotency, CSV, row errors, concurrency, retention)\n");
