<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use PeanutAdmin\Modules\Task\Contract\TaskScheduler;
use think\console\Input;
use think\console\Output;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

/**
 * 定时任务调度器。
 * 由系统 cron 每分钟调用一次：`* * * * * cd /path/to/server && php think crontab`
 * 每次调用扫描所有「运行中」任务，比对 cron 表达式，派发到期的 console 命令。
 */
class Crontab extends ContextualCommand
{
    public static function __make(\think\App $app): self
    {
        return new self(
            $app->make(TaskScheduler::class),
            $app->make(AdvisoryLockExecution::class),
            $app->make(ExecutionContextStore::class),
            $app->make(CurrentExecutionContext::class),
        );
    }

    public function __construct(
        private readonly ?TaskScheduler $taskScheduler = null,
        private readonly ?AdvisoryLockExecution $locks = null,
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $executionContext = null,
    ) {
        parent::__construct($contexts, $executionContext);
    }

    protected function configure()
    {
        $this->setName('crontab')->setDescription('定时任务调度器');
    }

    protected function establishesInstanceContext(): bool
    {
        return false;
    }

    protected function handle(Input $input, Output $output): int
    {
        $locks = $this->locks ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
        $scheduler = $this->taskScheduler ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
        try {
            $locks->run(
                'peanut:crontab:scheduler',
                0,
                fn() => $scheduler->runDue(time()),
            );
        } catch (AdvisoryLockUnavailable) {
            return 0;
        }

        return 0;
    }

    /** Compatibility entry for explicit trusted scheduler callers. */
    public function start(TenantScope $scope, array $item): void
    {
        ($this->taskScheduler ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED'))->start($scope, $item);
    }
}
