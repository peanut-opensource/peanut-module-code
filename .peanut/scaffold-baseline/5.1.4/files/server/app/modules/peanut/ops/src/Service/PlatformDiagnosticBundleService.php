<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Service;

use PeanutAdmin\Modules\Ops\Infrastructure\Authorization\PlatformOpsPermissionChecker;
use app\common\enum\instance\DeploymentMode;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use Composer\InstalledVersions;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Logs\RuntimeLogQuery;
use PeanutAdmin\Modules\Ops\Domain\Package;
use PeanutAdmin\Modules\Ops\Domain\Status\OpsStatusService;
use PeanutAdmin\Modules\Identity\Contract\TenantAuditDiagnosticQuery;
use PeanutAdmin\Modules\Task\Contract\TaskDiagnosticQuery;
use think\facade\Db;

/** Creates a fixed-schema JSON artifact without reading arbitrary files or raw log messages. */
final readonly class PlatformDiagnosticBundleService
{
    private const ALLOWED_WINDOWS = [60, 360, 1440];
    private const MAX_BYTES = 1048576;

    public function __construct(
        private PlatformOpsPermissionChecker $permissions,
        private Closure $runtimeLogs,
        private OpsStatusService $status,
        private ThinkPhpModuleGovernanceProvider $moduleGovernance,
        private TaskDiagnosticQuery $taskDiagnostics,
        private TenantAuditDiagnosticQuery $tenantAuditDiagnostics,
        private string $deploymentMode,
        private bool $debugEnabled,
    ) {}

    /** The console shares the diagnostic provider's bounded, redacted projection. */
    public function logs(PlatformContext $context, RuntimeLogQuery $query): array
    {
        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1440 minutes');
        return ($this->runtimeLogs)($since)->read($context, $query)->toPublicArray();
    }

    /** @return array{json:string,sha256:string,filename:string,bytes:int} */
    public function create(PlatformContext $context, int $windowMinutes): array
    {
        if (!in_array($windowMinutes, self::ALLOWED_WINDOWS, true)) {
            throw new \InvalidArgumentException('OPS_DIAGNOSTIC_WINDOW_INVALID');
        }

        if (!$this->permissions->allows($context, Package::READ_PERMISSION)
            || !$this->permissions->allows($context, Package::LOGS_PERMISSION)) {
            throw OpsConsoleException::denied();
        }

        $zone = new DateTimeZone('UTC');
        $generatedAt = new DateTimeImmutable('now', $zone);
        $since = $generatedAt->modify('-' . $windowMinutes . ' minutes');
        $status = $this->status
            ->read($context)
            ->toPublicArray();
        $modules = array_map(
            static fn(object $module): array => $module->toArray(),
            $this->moduleGovernance
                ->qualification()
                ->installedModules(),
        );
        if (count($modules) > 100) {
            throw new \RuntimeException('OPS_DIAGNOSTIC_MODULE_LIMIT_EXCEEDED');
        }

        $logs = ($this->runtimeLogs)($since)
            ->read($context, new RuntimeLogQuery('platform.audit', 'info', null, 100))
            ->toPublicArray();

        $mode = DeploymentMode::fromConfiguredValue($this->deploymentMode);
        $payload = [
            'generated_at' => $this->instant($generatedAt),
            'window' => [
                'minutes' => $windowMinutes,
                'from' => $this->instant($since),
                'to' => $this->instant($generatedAt),
            ],
            'limits' => [
                'maximum_bytes' => self::MAX_BYTES,
                'maximum_modules' => 100,
                'maximum_task_groups' => 200,
                'maximum_log_groups' => 100,
                'maximum_operation_logs' => 100,
            ],
            'redaction' => [
                'raw_log_files' => 'excluded',
                'raw_log_messages' => 'excluded',
                'credentials_and_tokens' => 'excluded',
                'request_headers_and_cookies' => 'excluded',
                'absolute_paths' => 'excluded',
                'personal_and_tenant_records' => 'excluded',
                'operation_log_payloads_and_identity' => 'excluded',
            ],
            'configuration' => [
                'deployment_mode' => $mode?->value ?? 'unconfigured',
                'debug_enabled' => $this->debugEnabled,
                'php_version' => PHP_VERSION,
                'core_package_version' => InstalledVersions::isInstalled('peanut-admin/core')
                    ? (InstalledVersions::getPrettyVersion('peanut-admin/core') ?? 'unknown')
                    : 'unknown',
            ],
            'runtime' => $status,
            'modules' => $modules,
            'failed_tasks' => [
                'instance' => $this->failedOpsTaskGroups($since),
                'tenant_aggregate' => $this->taskDiagnostics->failedGroupsSince($since, 100),
            ],
            'structured_logs' => [
                'source' => 'platform.audit',
                'items' => $logs['items'],
            ],
            'operation_logs' => [
                'source' => 'tenant.audit',
                'items' => $this->tenantAuditDiagnostics->recentOperationEvidence($since, 100),
            ],
        ];

        $payloadJson = $this->json($payload);
        $bundle = [
            'schema_version' => 1,
            'checksum_algorithm' => 'sha256',
            'payload_sha256' => hash('sha256', $payloadJson),
            'payload' => $payload,
        ];
        $json = $this->json($bundle) . "\n";
        $bytes = strlen($json);
        if ($bytes > self::MAX_BYTES) {
            throw new \RuntimeException('OPS_DIAGNOSTIC_SIZE_LIMIT_EXCEEDED');
        }
        $sha256 = hash('sha256', $json);

        return [
            'json' => $json,
            'sha256' => $sha256,
            'filename' => sprintf(
                'peanut-admin-diagnostics-%s-%s.json',
                $generatedAt->format('Ymd-His'),
                substr($sha256, 0, 12),
            ),
            'bytes' => $bytes,
        ];
    }

    /** @return list<array{task_type:string,status:string,error_code:string,occurrences:int,last_seen_at:string}> */
    private function failedOpsTaskGroups(DateTimeImmutable $since): array
    {
        $groups = [];
        $rows = Db::name('ops_task')->where('status', 'dead')->where('updated_at', '>=', $this->databaseInstant($since))
            ->field('task_type,status')->fieldRaw("COALESCE(last_error_code, 'TASK_ERROR_UNSPECIFIED') AS error_code,COUNT(*) AS occurrences,MAX(updated_at) AS last_seen_at")
            ->group('task_type,status,last_error_code')->order('last_seen_at', 'desc')->order('task_type')->limit(100)->select()->toArray();
        foreach ($rows as $row) {
            $taskType = (string) ($row['task_type'] ?? '');
            $errorCode = (string) ($row['error_code'] ?? '');
            $groups[] = [
                'task_type' => preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $taskType) === 1
                    ? $taskType
                    : 'task.unknown',
                'status' => 'dead',
                'error_code' => preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D', $errorCode) === 1
                    ? $errorCode
                    : 'TASK_ERROR_REDACTED',
                'occurrences' => min(1000000, max(1, (int) ($row['occurrences'] ?? 1))),
                'last_seen_at' => $this->instant(new DateTimeImmutable(
                    $this->databaseValue((string) ($row['last_seen_at'] ?? '')),
                    new DateTimeZone('UTC'),
                )),
            ];
        }
        return $groups;
    }

    private function instant(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function databaseInstant(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function databaseValue(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \RuntimeException('OPS_DIAGNOSTIC_TASK_TIME_INVALID');
        }
        return $value;
    }

    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
