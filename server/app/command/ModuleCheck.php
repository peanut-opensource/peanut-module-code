<?php

declare(strict_types=1);

namespace app\command;

use app\platform\services\plugin\ModuleAuthorCheckHost;
use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;

final class ModuleCheck extends ModuleContextualCommand
{
    private ?\PDO $preservationPdo = null;
    private ?array $preservationSelection = null;
    private ?array $preservationReferenceBatch = null;

    protected function configure()
    {
        $this->setName('module:check')->setDescription('Run the read-only Module author preflight')
            ->addArgument('module_key', Argument::REQUIRED, 'Namespaced Module key')
            ->addOption('kernel-version', null, Option::VALUE_REQUIRED, 'Selected Module Kernel version', '')
            ->addOption('package', null, Option::VALUE_REQUIRED, 'Optional Module package tar path', '')
            ->addOption('sha256', null, Option::VALUE_REQUIRED, 'Optional expected package SHA-256', '')
            ->addOption('runtime-data', null, Option::VALUE_NONE, 'Read module upgrade state under native development-maintenance authorization')
            ->addOption('preservation-details', null, Option::VALUE_NONE, 'Stream Workflow/Identity preservation observations in one read-only snapshot')
            ->addOption('tenants', null, Option::VALUE_REQUIRED, 'Explicit comma-separated tenant IDs')
            ->addOption('instances', null, Option::VALUE_REQUIRED, 'Explicit instance IDs or all within the selected tenants')
            ->addOption('database-resource', null, Option::VALUE_REQUIRED, 'Expected registered database resource ID')
            ->addOption('database-endpoint', null, Option::VALUE_REQUIRED, 'Expected registered endpoint ID')
            ->addOption('database-name', null, Option::VALUE_REQUIRED, 'Expected registered database name')
            ->addOption('candidate', null, Option::VALUE_REQUIRED, 'Exact current Git HEAD for the observation')
            ->addOption('page-size', null, Option::VALUE_REQUIRED, 'Bounded records per output page', '100')
            ->addOption('document-file-preservation', null, Option::VALUE_NONE, 'Include optional installed Doc/File metadata without enabling business operations')
            ->addOption('documents', null, Option::VALUE_REQUIRED, 'Explicit document IDs or all within selected tenants')
            ->addOption('file-scope', null, Option::VALUE_REQUIRED, 'references or all-in-selected-tenants; never instance-global storage');
    }

    /** Live authorization adapter: modules verify the actual command lifecycle and its private snapshot ownership. */
    public function assertPreservationReadSnapshot(\PDO $pdo, string $moduleKey): array
    {
        $this->assertDevelopmentInstanceMaintenanceAccess();
        if ($this->getName() !== 'module:check' || $this->preservationPdo !== $pdo || $this->preservationSelection === null
            || !$pdo->inTransaction() || Db::connect()->getPdo() !== $pdo
            || $this->getApp()->make(\app\common\execution\CurrentExecutionContext::class) !== $this->executionContext()
            || !in_array($moduleKey, ['peanut.document', 'peanut.file'], true)) {
            throw new \RuntimeException('PRESERVATION_MODULE_SNAPSHOT_UNAUTHORIZED');
        }
        $modules = new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries();
        $manifest = $this->getApp()->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class)->requireManifest($moduleKey);
        $installation = $modules->installationIdentities([$moduleKey])[$moduleKey] ?? null;
        if (!is_array($installation)
            || (string) ($installation['installed_version'] ?? '') !== (string) ($manifest->data['version'] ?? '')
            || (int) ($installation['manifest_schema_version'] ?? 0) !== (int) ($manifest->data['schema_version'] ?? 0)
            || !hash_equals($manifest->digest, (string) ($installation['manifest_digest'] ?? ''))) {
            throw new \RuntimeException('PRESERVATION_OPTIONAL_MODULE_IDENTITY_UNKNOWN');
        }
        $files = glob($this->getApp()->getRootPath() . 'database/migrations/*.php') ?: [];
        $applied = array_map('strval', Db::name((string) config('database.migration_table', 'migrations'))->column('version'));
        if ($files === []) {
            throw new \RuntimeException('PRESERVATION_APPLICATION_MIGRATIONS_UNKNOWN');
        }
        foreach ($files as $file) {
            if (!\Phinx\Util\Util::isValidMigrationFileName(basename($file))
                || !in_array((string) \Phinx\Util\Util::getVersionFromFileName(basename($file)), $applied, true)) {
                throw new \RuntimeException('PRESERVATION_APPLICATION_MIGRATIONS_PENDING');
            }
        }
        $states = $modules->installationStates([$moduleKey]);
        $state = $states[$moduleKey] ?? null;
        if (!is_array($state) || !in_array($state['status'] ?? null, ['active', 'maintenance'], true)
            || ($state['last_error_code'] ?? null) !== null || Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
            throw new \RuntimeException('PRESERVATION_OPTIONAL_MODULE_UNAVAILABLE');
        }
        return $this->preservationSelection + ['module_status' => $state['status']];
    }

    /** Only the Doc page currently being consumed can authorize a File-owned reference lookup batch. */
    public function assertPreservationReferenceBatch(\PDO $pdo, array $references): void
    {
        $scope = $this->assertPreservationReadSnapshot($pdo, 'peanut.file');
        if ($this->preservationReferenceBatch === null || $references !== $this->preservationReferenceBatch || count($references) > 100) {
            throw new \RuntimeException('PRESERVATION_REFERENCE_BATCH_UNAUTHORIZED');
        }
        foreach ($references as $reference) {
            if (!in_array($reference['tenant_id'] ?? null, $scope['tenants'], true)
                || ($scope['documents'] !== null && !in_array($reference['document_id'] ?? null, $scope['documents'], true))) {
                throw new \RuntimeException('PRESERVATION_REFERENCE_SCOPE_INVALID');
            }
        }
    }

    protected function handle(Input $input, Output $output): int
    {
        if ($input->getOption('preservation-details')) {
            return $this->preservationDetails($input, $output);
        }
        try {
            if ($input->getOption('runtime-data')) {
                $this->assertDevelopmentInstanceMaintenanceAccess();
                $query = match ($input->getArgument('module_key')) {
                    'peanut.workflow' => \PeanutAdmin\Modules\Workflow\Application\WorkflowUpgradePreflight::class,
                    'peanut.notification' => \PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::class,
                    default => throw new \InvalidArgumentException('MODULE_RUNTIME_PREFLIGHT_UNAVAILABLE'),
                };
                $report = $this->getApp()->make($query)->report();
                $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return $report['may_apply'] ? 0 : 1;
            }
            $kernelVersion = trim((string) $input->getOption('kernel-version'));
            if ($kernelVersion === '') {
                $configured = trim((string) Config::get('modules.kernel_version', '1.0.0'));
                $kernelVersion = $configured === '' ? '1.0.0' : $configured;
            }
            $result = (new ModuleAuthorCheckHost(dirname(__DIR__, 3), $kernelVersion))->inspect(
                (string) $input->getArgument('module_key'),
                (string) $input->getOption('package'),
                (string) $input->getOption('sha256'),
            );
            $output->writeln((string) json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            return $result['status'] === 'ready' ? 0 : 1;
        } catch (\Throwable) {
            $output->writeln((string) json_encode([
                'status' => 'blocked',
                'code' => 'MODULE_CHECK_FAILED',
                'reason' => 'Module 作者检查无法完成',
                'remediation' => '确认项目源码与 Composer 依赖完整后重新运行 module:check',
                'checks' => [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return 1;
        }
    }

    /** This separate maintenance mode never changes the lightweight runtime-data or lifecycle contracts. */
    private function preservationDetails(Input $input, Output $output): int
    {
        $pdo = null;
        $started = false;
        $sequence = 0;
        $previous = null;
        $run = bin2hex(random_bytes(16));
        $canonical = static function (array $value): string {
            $normalize = static function (array $items) use (&$normalize): array {
                if (!array_is_list($items)) { ksort($items, SORT_STRING); }
                foreach ($items as $key => $item) { if (is_array($item)) { $items[$key] = $normalize($item); } }
                return $items;
            };
            return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };
        $emit = static function (array $value) use ($output, $canonical, $run, &$sequence, &$previous): void {
            $value = ['protocol' => 'peanut.workflow-preservation-details.v1', 'run_id' => $run,
                'sequence' => $sequence++, 'previous_sha256' => $previous] + $value;
            $value['page_sha256'] = hash('sha256', $canonical($value));
            $output->writeln($canonical($value));
            $previous = $value['page_sha256'];
        };
        try {
            $this->assertDevelopmentInstanceMaintenanceAccess();
            if ($this->getName() !== 'module:check' || $input->getArgument('module_key') !== 'peanut.workflow'
                || $input->getOption('runtime-data') || $input->getOption('package') !== '' || $input->getOption('sha256') !== '') {
                throw new \RuntimeException('PRESERVATION_DETAIL_SCOPE_INVALID');
            }
            $ids = static function (string $value): array {
                $items = explode(',', $value);
                if ($value === '' || count($items) > 100 || count(array_unique($items)) !== count($items)) {
                    throw new \RuntimeException('PRESERVATION_DETAIL_SCOPE_INVALID');
                }
                foreach ($items as $id) {
                    if (preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) !== 1) { throw new \RuntimeException('PRESERVATION_DETAIL_SCOPE_INVALID'); }
                }
                usort($items, static fn(string $a, string $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
                return $items;
            };
            $tenants = $ids((string) $input->getOption('tenants'));
            $instanceScope = (string) $input->getOption('instances');
            $instances = $instanceScope === 'all' ? null : $ids($instanceScope);
            $includeDocFile = (bool) $input->getOption('document-file-preservation');
            $documents = null; $fileScope = null;
            if ($includeDocFile) {
                $documentScope = (string) $input->getOption('documents');
                $documents = $documentScope === 'all' ? null : $ids($documentScope);
                $fileScope = (string) $input->getOption('file-scope');
                if (!in_array($fileScope, ['references', 'all-in-selected-tenants'], true)) { throw new \RuntimeException('PRESERVATION_DETAIL_SCOPE_INVALID'); }
            } elseif ($input->getOption('documents') !== null || $input->getOption('file-scope') !== null) {
                throw new \RuntimeException('PRESERVATION_DETAIL_SCOPE_INVALID');
            }
            $limit = (string) $input->getOption('page-size');
            if (preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/D', $limit) !== 1) { throw new \RuntimeException('PRESERVATION_DETAIL_SCOPE_INVALID'); }
            $root = dirname(__DIR__, 2);
            $candidate = (string) $input->getOption('candidate');
            $sourceIdentity = static function () use ($root, $candidate, $includeDocFile): array {
                if (preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1) { throw new \RuntimeException('PRESERVATION_DETAIL_SOURCE_UNKNOWN'); }
                $process = proc_open(['git', '-C', dirname($root), 'rev-parse', '--verify', 'HEAD'],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                if (!is_resource($process)) { throw new \RuntimeException('PRESERVATION_DETAIL_SOURCE_UNKNOWN'); }
                $head = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]);
                if (proc_close($process) !== 0 || $head !== $candidate) { throw new \RuntimeException('PRESERVATION_DETAIL_SOURCE_UNKNOWN'); }
                $files = [];
                foreach (['app/command/ModuleCheck.php', 'app/modules/peanut/workflow/src/Application/WorkflowUpgradePreflight.php',
                    'app/modules/peanut/workflow/src/Definition/WorkflowGraph.php', 'app/modules/peanut/identity/src/Contract/WorkflowLegacyOrganizationQuery.php',
                    'composer.lock', '../plugins.lock'] as $relative) {
                    $path = $root . '/' . $relative;
                    if (!is_file($path) || is_link($path)) { throw new \RuntimeException('PRESERVATION_DETAIL_SOURCE_UNKNOWN'); }
                    $files[$relative] = hash_file('sha256', $path);
                }
                if ($includeDocFile) {
                    foreach (['app/modules/peanut/document/src/Contract/DocumentUpgradePreservationQuery.php', 'app/modules/peanut/document/module.json',
                        'app/modules/peanut/file/src/Contract/StorageQualificationQueries.php', 'app/modules/peanut/file/module.json'] as $relative) {
                        $path = $root . '/' . $relative;
                        if (is_link($path)) { throw new \RuntimeException('PRESERVATION_DETAIL_SOURCE_UNKNOWN'); }
                        $files[$relative] = is_file($path) ? hash_file('sha256', $path) : 'absent';
                    }
                }
                return ['candidate' => $head, 'actual_file_sha256' => $files, 'lock_qualification' => 'not_asserted_by_this_report'];
            };
            $source = $sourceIdentity();
            require_once $root . '/database/environment-guard.php';
            $registered = guardedDatabaseConfig();
            projectResourceRegistry($registrySha);
            if ($registered['resource_id'] !== $input->getOption('database-resource')
                || $registered['endpoint_id'] !== $input->getOption('database-endpoint')
                || $registered['database'] !== $input->getOption('database-name') || $registered['environment'] !== 'development') {
                throw new \RuntimeException('PRESERVATION_DETAIL_DATABASE_MISMATCH');
            }
            $connection = Db::connect();
            $config = $connection->getConfig();
            if (($config['type'] ?? null) !== 'mysql' || !empty($config['deploy']) || !empty($config['rw_separate'])
                || !empty($config['break_reconnect']) || (string) $config['hostname'] !== $registered['host']
                || (string) $config['hostport'] !== $registered['port'] || (string) $config['database'] !== $registered['database']) {
                throw new \RuntimeException('PRESERVATION_DETAIL_CONNECTION_UNSUPPORTED');
            }
            $connection->query('SELECT DATABASE() AS selected_database', [], true);
            $pdo = $connection->getPdo();
            if (!$pdo instanceof \PDO || $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION || $pdo->inTransaction()) {
                throw new \RuntimeException('PRESERVATION_DETAIL_CONNECTION_UNSUPPORTED');
            }
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $started = true;
            if (!$pdo->inTransaction()) { throw new \RuntimeException('PRESERVATION_DETAIL_SNAPSHOT_LOST'); }
            $identity = $pdo->query('SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id')->fetch(\PDO::FETCH_ASSOC);
            if (($identity['database_name'] ?? null) !== $registered['database']) { throw new \RuntimeException('PRESERVATION_DETAIL_DATABASE_MISMATCH'); }
            if ($includeDocFile) {
                $this->preservationPdo = $pdo;
                $this->preservationSelection = ['tenants' => $tenants, 'documents' => $documents, 'prefix' => (string) $config['prefix'],
                    'limit' => (int) $limit, 'file_scope' => $fileScope];
            }
            $emit(['kind' => 'header', 'schema_version' => 1, 'source' => $source, 'started_at' => gmdate(DATE_ATOM),
                'database' => ['resource_id' => $registered['resource_id'], 'endpoint_id' => $registered['endpoint_id'],
                    'database' => $registered['database'], 'registry_sha256' => $registrySha,
                    'server_uuid' => $identity['server_uuid'], 'connection_id' => (string) $identity['connection_id']],
                'scope' => ['tenants' => $tenants, 'instances' => $instances ?? 'all_in_selected_tenants', 'definitions' => 'all_in_selected_tenants', 'departments' => 'all_in_selected_tenants',
                    'document_file_requested' => $includeDocFile, 'documents' => $includeDocFile ? ($documents ?? 'all_in_selected_tenants') : 'not_requested',
                    'files' => $fileScope, 'instance_global_resources' => 'excluded'],
                'snapshot' => 'same PDO; repeatable read; read only; consistent snapshot; no cross-run continuation',
                'may_apply' => false, 'mapping_correctness_proven' => false, 'preservation_after_upgrade_proven' => false]);
            $queries = [
                'peanut.workflow' => $this->getApp()->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowUpgradePreflight::class)
                    ->detailPages($pdo, (string) $config['prefix'], $tenants, $instances, (int) $limit, $this->executionContext()),
                'peanut.identity' => $this->getApp()->make(\PeanutAdmin\Modules\Identity\Contract\WorkflowLegacyOrganizationQuery::class)
                    ->detailPages($pdo, (string) $config['prefix'], $tenants, (int) $limit, $this->executionContext()),
            ];
            $complete = true; $counts = []; $classifications = []; $totals = []; $finished = [];
            foreach ($queries as $module => $pages) {
                foreach ($pages as $page) {
                    if ($connection->getPdo() !== $pdo || !$pdo->inTransaction()) { throw new \RuntimeException('PRESERVATION_DETAIL_SNAPSHOT_LOST'); }
                    $complete = $complete && $page['complete'] === true;
                    $table = $page['table'];
                    if ($page['complete'] === true) {
                        $totals[$table] = $page['total_rows'];
                        $finished[$table] = ($page['table_finished'] ?? false) === true;
                    }
                    $counts[$table] = ($page['complete'] === true && (!array_key_exists($table, $counts) || $counts[$table] !== null))
                        ? ($counts[$table] ?? 0) + count($page['records']) : null;
                    foreach ($page['records'] as $record) { $key = $record['classification']; $classifications[$key] = ($classifications[$key] ?? 0) + 1; }
                    $emit(['kind' => 'records', 'module_key' => $module, 'page' => $page]);
                }
            }
            $docFileConsistent = true;
            if ($includeDocFile) {
                $consume = function (string $module, array $page) use ($emit, &$complete, &$counts, &$totals, &$finished, &$classifications, &$docFileConsistent): void {
                    $complete = $complete && ($page['complete'] ?? false) === true;
                    $table = $page['table'];
                    if (($page['complete'] ?? false) === true) { $totals[$table] = $page['total_rows']; $finished[$table] = ($page['table_finished'] ?? false) === true; }
                    $counts[$table] = ($page['complete'] ?? false) === true && (!array_key_exists($table, $counts) || $counts[$table] !== null)
                        ? ($counts[$table] ?? 0) + count($page['records']) : null;
                    foreach ($page['records'] as $record) {
                        $key = $record['classification']; $classifications[$key] = ($classifications[$key] ?? 0) + 1;
                        if ($key === 'unknown') { $docFileConsistent = false; }
                    }
                    $emit(['kind' => 'records', 'module_key' => $module, 'page' => $page]);
                };
                $file = null;
                try {
                    if (!class_exists(\PeanutAdmin\Modules\File\Contract\StorageQualificationQueries::class)) { throw new \RuntimeException('PRESERVATION_OPTIONAL_MODULE_UNAVAILABLE'); }
                    $this->assertPreservationReadSnapshot($pdo, 'peanut.file');
                    $file = $this->getApp()->make(\PeanutAdmin\Modules\File\Contract\StorageQualificationQueries::class);
                } catch (\Throwable) {
                    $complete = false; $docFileConsistent = false;
                    $emit(['kind' => 'optional-module', 'module_key' => 'peanut.file', 'complete' => false, 'code' => 'FILE_MAINTENANCE_UNAVAILABLE']);
                }
                try {
                    if (!class_exists(\PeanutAdmin\Modules\Document\Contract\DocumentUpgradePreservationQuery::class)) { throw new \RuntimeException('PRESERVATION_OPTIONAL_MODULE_UNAVAILABLE'); }
                    $document = $this->getApp()->make(\PeanutAdmin\Modules\Document\Contract\DocumentUpgradePreservationQuery::class);
                    foreach ($document->preservationPages($pdo, (string) $config['prefix'], $tenants, $documents, (int) $limit, $this) as $page) {
                        $consume('peanut.document', $page);
                        $references = [];
                        foreach ($page['records'] as $record) { foreach ($record['references'] as $reference) { $references[] = $reference; } }
                        if ($references === []) { continue; }
                        $this->preservationReferenceBatch = $references;
                        try {
                            if ($file === null) { throw new \RuntimeException('PRESERVATION_OPTIONAL_MODULE_UNAVAILABLE'); }
                            $results = $file->documentReferenceMetadata($pdo, (string) $config['prefix'], $tenants, $references, $this);
                            if (count($results) !== count($references)) { throw new \RuntimeException('PRESERVATION_REFERENCE_BATCH_INCOMPLETE'); }
                            foreach ($results as $result) { if ($result['classification'] !== 'metadata_reference_consistent') { $docFileConsistent = false; } }
                            $emit(['kind' => 'reference-check', 'input_count' => count($references), 'result_count' => count($results), 'results' => $results, 'bytes_verified' => false]);
                        } catch (\Throwable) {
                            $complete = false; $docFileConsistent = false;
                            $emit(['kind' => 'reference-check', 'input_count' => count($references), 'result_count' => null,
                                'complete' => false, 'code' => 'FILE_REFERENCE_BATCH_UNKNOWN', 'bytes_verified' => false]);
                        } finally { $this->preservationReferenceBatch = null; }
                    }
                } catch (\Throwable) {
                    $complete = false; $docFileConsistent = false;
                    $emit(['kind' => 'optional-module', 'module_key' => 'peanut.document', 'complete' => false, 'code' => 'DOCUMENT_MAINTENANCE_UNAVAILABLE']);
                }
                if ($file !== null && $fileScope === 'all-in-selected-tenants') {
                    try {
                        foreach ($file->preservationPages($pdo, (string) $config['prefix'], $tenants, (int) $limit, $this) as $page) { $consume('peanut.file', $page); }
                    } catch (\Throwable) {
                        $complete = false; $docFileConsistent = false;
                        $emit(['kind' => 'optional-module', 'module_key' => 'peanut.file', 'complete' => false, 'code' => 'FILE_INVENTORY_UNKNOWN']);
                    }
                }
            }
            foreach ($counts as $table => $count) {
                if ($count === null || (string) $count !== ($totals[$table] ?? null) || !($finished[$table] ?? false)) { $complete = false; }
            }
            projectResourceRegistry($endRegistrySha);
            if ($sourceIdentity() !== $source || $endRegistrySha !== $registrySha || $connection->getPdo() !== $pdo || !$pdo->inTransaction()) {
                throw new \RuntimeException('PRESERVATION_DETAIL_SNAPSHOT_LOST');
            }
            $pdo->rollBack(); $started = false;
            $complete = $complete && $docFileConsistent;
            $emit(['kind' => 'finish', 'complete' => $complete, 'finished_at' => gmdate(DATE_ATOM), 'doc_file_metadata_consistent' => $includeDocFile ? $docFileConsistent : null,
                'record_counts' => $counts, 'classifications' => $classifications,
                'may_apply' => false, 'mapping_correctness_proven' => false, 'preservation_after_upgrade_proven' => false,
                'code' => $complete ? null : 'PRESERVATION_DETAIL_SCHEMA_UNKNOWN']);
            return $complete ? 0 : 1;
        } catch (\Throwable $exception) {
            $emit(['kind' => 'finish', 'complete' => false, 'code' => 'PRESERVATION_DETAIL_UNAVAILABLE',
                'diagnostic_sha256' => hash('sha256', get_class($exception) . ':' . $exception->getMessage()),
                'may_apply' => false, 'mapping_correctness_proven' => false, 'preservation_after_upgrade_proven' => false]);
            return 1;
        } finally {
            $this->preservationPdo = null; $this->preservationSelection = null; $this->preservationReferenceBatch = null;
            if ($started && $pdo instanceof \PDO && $pdo->inTransaction()) {
                try { $pdo->rollBack(); }
                catch (\Throwable) { $emit(['kind' => 'cleanup', 'complete' => false, 'code' => 'PRESERVATION_DETAIL_ROLLBACK_UNCONFIRMED']); }
            }
        }
    }
}
