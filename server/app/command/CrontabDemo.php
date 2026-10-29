<?php

declare(strict_types=1);

namespace app\command;

use PeanutAdmin\Modules\Ops\Domain\Logs\TenantDiagnosticAttributes;
use app\common\infrastructure\runtime\OperationalLog;
use PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext;
use app\common\execution\ContextualCommand;
use think\console\Input;
use think\console\Output;

/**
 * 定时任务演示命令。
 * 供「定时任务」模块开箱验证：被调度时向 runtime 日志写入一条记录。
 * 命令名填 `crontab:demo`，可用于确认调度链路是否打通。
 */
class CrontabDemo extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('crontab:demo')->setDescription('定时任务演示命令');
    }

    protected function handle(Input $input, Output $output): int
    {
        $scope = ScheduledTenantContext::require();
        $diagnostics = TenantDiagnosticAttributes::fromScope($scope);
        $msg = sprintf(
            '[crontab:demo] tenant_id=%d executed at %s',
            $scope->tenantId(),
            date('Y-m-d H:i:s'),
        );
        OperationalLog::info($this->executionContext(), 'crontab_demo_executed', $diagnostics + ['message' => $msg]);
        $output->writeln($msg);
        return 0;
    }
}
