<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Service;

use PeanutAdmin\Modules\Ops\Infrastructure\PairedBackupProvider;
use app\platform\value\ops\PairedBackupManifest;
use app\platform\value\ops\RestoreVerificationEvidence;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Package;
use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProviderRegistry;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskService;
use Throwable;
use think\facade\Db;

/** Read-only projection for recent operations tasks and the latest verified backup pair. */
final readonly class PlatformBackupCenterService
{
    public function __construct(
        private BackupRestoreProviderRegistry $backupProviders,
        private OpsTaskService $tasks,
        private PlatformPermissionChecker $permissions,
    ) {}

    /** Read-only history; unknown runtime identity never proves a source match. @return array<string,mixed> */
    public function snapshot(PlatformContext $context, ?string $runtimeCommit, int $page = 1, int $pageSize = 20): array
    {
        if (!$this->permissions->allows($context, Package::READ_PERMISSION)) {
            throw OpsConsoleException::denied();
        }

        $descriptor = $this->backupProviders
            ->require(PairedBackupProvider::PROVIDER_KEY);
        $provider = [
            'key' => $descriptor->key,
        ];
        if (($runtimeCommit !== null && preg_match('/^[a-f0-9]{40}$/D', $runtimeCommit) !== 1)
            || $page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw OpsConsoleException::statusUnavailable();
        }

        $backupContractStatus = 'missing';
        $restoreContractStatus = 'missing';
        $latestBackup = $this->latestVerified($runtimeCommit, $backupContractStatus);
        $latestRestore = $this->latestRestoreVerified($restoreContractStatus);
        return [
            'provider' => $provider,
            'latest_verified' => $latestBackup,
            'latest_restore_verified' => $latestRestore,
            'backup_contract_status' => $backupContractStatus,
            'restore_contract_status' => $restoreContractStatus,
            'new_paired_backup_required' => $backupContractStatus === 'unsupported_schema'
                || $restoreContractStatus === 'unsupported_schema',
            'source_identity_known' => $runtimeCommit !== null,
            'tasks' => $this->recentTasks($context, $page, $pageSize),
            'page' => $page, 'page_size' => $pageSize,
            'total' => Db::name('ops_task')->whereIn('task_type', [Package::BACKUP_TASK_TYPE, Package::RESTORE_TASK_TYPE])->count(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function recentTasks(PlatformContext $context, int $page, int $pageSize): array
    {
        $tasks = [];
        $taskKeys = Db::name('ops_task')->whereIn('task_type', [Package::BACKUP_TASK_TYPE, Package::RESTORE_TASK_TYPE])
            ->order('id', 'desc')->page($page, $pageSize)->column('task_key');
        foreach ($taskKeys as $taskKey) {
            $tasks[] = $this->tasks
                ->task($context, (string) $taskKey)
                ->toPublicArray();
        }
        return $tasks;
    }

    /** @return array<string,mixed>|null */
    private function latestVerified(?string $runtimeCommit, string &$contractStatus): ?array
    {
        $row = Db::name('ops_backup_evidence')
            ->field('backup_reference_key,task_key,provider_key,manifest_sha256,source_commit,source_tree,source_release_key,consistency_started_at,consistency_completed_at,verified_at,manifest_json')
            ->order('verified_at', 'desc')->order('id', 'desc')->find();
        if (!is_array($row)) {
            return null;
        }

        try {
            $manifestJson = (string) $row['manifest_json'];
            $historical = json_decode($manifestJson, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($historical) && in_array($historical['schema_version'] ?? null, [2, 3], true)) {
                $contractStatus = 'unsupported_schema';
                return null;
            }
            $manifest = PairedBackupManifest::fromJson($manifestJson);
            if (!hash_equals(hash('sha256', $manifest->canonicalJson()), (string) $row['manifest_sha256'])
                || !hash_equals($manifest->backupReferenceKey(), (string) $row['backup_reference_key'])
            ) {
                throw new \RuntimeException('OPS_BACKUP_EVIDENCE_INVALID');
            }
            $verifiedAt = $this->instant((string) $row['verified_at']);
            $ageSeconds = max(0, time() - (new DateTimeImmutable($verifiedAt))->getTimestamp());
            $contractStatus = 'current';

            return [
                'backup_reference_key' => (string) $row['backup_reference_key'],
                'task_key' => (string) $row['task_key'],
                'provider_key' => (string) $row['provider_key'],
                'manifest_sha256' => (string) $row['manifest_sha256'],
                'source_commit' => (string) $row['source_commit'],
                'source_tree' => (string) $row['source_tree'],
                'source_release_key' => $row['source_release_key'] === null
                    ? null
                    : (string) $row['source_release_key'],
                'consistency_started_at' => $this->instant((string) $row['consistency_started_at']),
                'consistency_completed_at' => $this->instant((string) $row['consistency_completed_at']),
                'verified_at' => $verifiedAt,
                'age_seconds' => $ageSeconds,
                'source_matches_runtime' => $runtimeCommit === null ? null : hash_equals((string) $row['source_commit'], $runtimeCommit),
            ];
        } catch (Throwable) {
            throw OpsConsoleException::taskUnavailable();
        }
    }

    /** @return array<string,mixed>|null */
    private function latestRestoreVerified(string &$contractStatus): ?array
    {
        $row = Db::name('ops_restore_evidence')
            ->field('backup_reference_key,target_key,evidence_sha256,table_count,schema_migration_count,account_count,tenant_count,tenant_member_count,storage_file_count,verified_at,evidence_json')
            ->order('verified_at', 'desc')->order('id', 'desc')->find();
        if (!is_array($row)) {
            return null;
        }
        try {
            $historical = json_decode((string) $row['evidence_json'], true, 32, JSON_THROW_ON_ERROR);
            if (is_array($historical) && in_array($historical['schema_version'] ?? null, [2, 3], true)) {
                $contractStatus = 'unsupported_schema';
                return null;
            }
            $evidence = RestoreVerificationEvidence::fromJson((string) $row['evidence_json']);
            $data = $evidence->toArray();
            if ((string) $row['target_key'] !== PairedBackupProvider::RESTORE_TARGET_KEY
                || !hash_equals(hash('sha256', $evidence->canonicalJson()), (string) $row['evidence_sha256'])
                || !hash_equals($data['backup_reference_key'], (string) $row['backup_reference_key'])
            ) {
                throw new \RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
            }
        } catch (Throwable) {
            throw OpsConsoleException::taskUnavailable();
        }
        $contractStatus = 'current';
        return [
            'backup_reference_key' => (string) $row['backup_reference_key'],
            'target_key' => (string) $row['target_key'],
            'verified_at' => $this->instant((string) $row['verified_at']),
            'verification_sha256' => (string) $row['evidence_sha256'],
            'table_count' => (int) $row['table_count'],
            'migration_count' => (int) $row['schema_migration_count'],
            'tenant_count' => (int) $row['tenant_count'],
            'account_count' => (int) $row['account_count'],
            'tenant_member_count' => (int) $row['tenant_member_count'],
            'file_count' => (int) $row['storage_file_count'],
            'private_file_count' => $data['verification']['private_storage_file_count'],
        ];
    }

    private function instant(string $value): string
    {
        $normalized = str_replace(' ', 'T', trim($value));
        if (!str_contains($normalized, '.')) {
            $normalized .= '.000';
        }
        $instant = $normalized . 'Z';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $instant, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new \RuntimeException('OPS_BACKUP_EVIDENCE_TIME_INVALID');
        }
        return $instant;
    }
}
