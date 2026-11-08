<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Remove only the retired diagnostic command and its completed, self-contained execution records. */
final class RemoveRetiredDemoSchedule extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('RemoveRetiredDemoSchedule requires the native PDO adapter.');
        }
        foreach (['pa_crontab', 'pa_task_job', 'pa_task_job_attempt', 'pa_task_job_event'] as $table) {
            if (!$adapter->hasTable($table)) {
                return;
            }
        }
        $pdo = $adapter->getConnection();
        // The native migration runner owns the transaction. Lock the exact schedule and job graph.
        $schedules = $pdo->query("SELECT tenant_id, id FROM pa_crontab WHERE BINARY command = BINARY 'crontab:demo' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $findJobs = $pdo->prepare("SELECT id, task_type, handler_key, status, completed_at FROM pa_task_job WHERE tenant_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.schedule_id')) = ? FOR UPDATE");
        $deleteEvents = $pdo->prepare('DELETE FROM pa_task_job_event WHERE tenant_id = ? AND job_id = ?');
        $deleteAttempts = $pdo->prepare('DELETE FROM pa_task_job_attempt WHERE tenant_id = ? AND job_id = ?');
        $deleteJob = $pdo->prepare("DELETE FROM pa_task_job WHERE tenant_id = ? AND id = ? AND task_type = 'peanut.task.crontab' AND handler_key = 'peanut.task.crontab.execute' AND status = 'succeeded' AND completed_at IS NOT NULL");
        $deleteSchedule = $pdo->prepare("DELETE FROM pa_crontab WHERE tenant_id = ? AND id = ? AND BINARY command = BINARY 'crontab:demo'");
        foreach ($schedules as $schedule) {
            $tenantId = $schedule['tenant_id'];
            $scheduleId = $schedule['id'];
            $findJobs->execute([$tenantId, (string) $scheduleId]);
            $jobs = $findJobs->fetchAll(PDO::FETCH_ASSOC);
            // Never strand active jobs or delete records with an unrecognized owner/handler/state.
            foreach ($jobs as $job) {
                if ($job['task_type'] !== 'peanut.task.crontab'
                    || $job['handler_key'] !== 'peanut.task.crontab.execute'
                    || $job['status'] !== 'succeeded'
                    || $job['completed_at'] === null) {
                    continue 2;
                }
            }
            foreach ($jobs as $job) {
                $identity = [$tenantId, $job['id']];
                $deleteEvents->execute($identity);
                $deleteAttempts->execute($identity);
                $deleteJob->execute($identity);
                if ($deleteJob->rowCount() !== 1) {
                    throw new RuntimeException('RETIRED_DEMO_JOB_CHANGED');
                }
            }
            $deleteSchedule->execute([$tenantId, $scheduleId]);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Retired diagnostic records can only be recovered from the pre-upgrade backup.');
    }
}
