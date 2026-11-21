<?php

declare(strict_types=1);

namespace app\command;

use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\runtime\OperationalLog;
use app\common\execution\ContextualCommand;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;

final class TenantTaskWorker extends ContextualCommand
{
    public function __construct(
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $executionContext = null,
        private readonly ?TaskJobRuntime $runtime = null,
    ) {
        parent::__construct($contexts, $executionContext);
    }

    protected function configure()
    {
        $this->setName('tenant-task:work')
            ->setDescription('执行一个 Tenant 的可信异步任务')
            ->addArgument('tenant_id', Argument::REQUIRED, 'Tenant ID')
            ->addOption('job-key', null, Option::VALUE_REQUIRED, 'Process only this exact job');
    }

    protected function establishesInstanceContext(): bool
    {
        return false;
    }

    protected function handle(Input $input, Output $output): int
    {
        $raw = (string) $input->getArgument('tenant_id');
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
            $output->writeln('[tenant-task:work] invalid tenant');
            return 1;
        }
        $jobKey = $input->getOption('job-key');
        if ($jobKey !== null && (!is_string($jobKey) || preg_match('/^job_[0-9a-f]{32}$/D', $jobKey) !== 1)) {
            $output->writeln('[tenant-task:work] invalid job key');
            return 1;
        }
        try {
            $processed = $this->runtime()->runTenant(
                (int) $raw,
                'tenant-worker-' . getmypid() . '-' . bin2hex(random_bytes(6)),
                $jobKey,
            );
            $output->writeln(sprintf('[tenant-task:work] tenant=%d processed=%d', (int) $raw, $processed));
            return 0;
        } catch (\Throwable $exception) {
            OperationalLog::error($this->executionContext(), 'tenant_task_worker_startup_failed', [
                'tenant_id' => (int) $raw,
                'failure_code' => self::startupFailureCode($exception),
            ]);
            $output->writeln('[tenant-task:work] failed');
            return 1;
        }
    }

    private static function startupFailureCode(\Throwable $exception): string
    {
        return in_array($exception->getMessage(), [
            'ASYNC_SIGNING_KEY_INVALID',
            'MODULE_CONTEXT_INVALID',
            'TASK_AUTHORIZATION_DUPLICATE',
            'TASK_AUTHORIZATION_INVALID',
            'TASK_JOB_INVALID',
            'TASK_WORKER_DEFINITION_REQUIRED',
        ], true) ? $exception->getMessage() : 'TENANT_TASK_WORKER_STARTUP_FAILED';
    }

    private function runtime(): TaskJobRuntime
    {
        return $this->runtime
            ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
    }
}
