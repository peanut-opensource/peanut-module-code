<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Ops\Contract\UpgradeTaskExecution;
use app\common\execution\ContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/** Deployment-control bridge for the fixed PC42 upgrade state machine. */
final class OpsUpgradeTask extends ContextualCommand
{
    public function __construct(
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $executionContext = null,
        private readonly ?UpgradeTaskExecution $service = null,
    ) {
        parent::__construct($contexts, $executionContext);
    }

    protected function configure(): void
    {
        $this->setName('ops-upgrade:task')
            ->addArgument('action', Argument::REQUIRED, 'claim, advance, heartbeat, succeed, or fail')
            ->addOption('task-key', null, Option::VALUE_OPTIONAL, 'Opaque task key')
            ->addOption('revision', null, Option::VALUE_OPTIONAL, 'Claimed execution revision')
            ->addOption('error-code', null, Option::VALUE_OPTIONAL, 'Stable allowlisted failure code')
            ->setDescription('Advance one fixed application-upgrade task');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $action = trim((string) $input->getArgument('action'));
            $result = match ($action) {
                'claim' => $this->service()->claim(),
                'advance' => $this->service()->advance($this->taskKey($input), $this->revision($input)),
                'heartbeat' => $this->service()->heartbeat($this->taskKey($input), $this->revision($input)),
                'succeed' => $this->service()->succeed($this->taskKey($input), $this->revision($input)),
                'fail' => $this->service()->fail(
                    $this->taskKey($input),
                    $this->revision($input),
                    trim((string) $input->getOption('error-code')),
                ),
                default => throw new \InvalidArgumentException('OPS_UPGRADE_ACTION_INVALID'),
            };
            $output->writeln(json_encode(
                ['ok' => true, 'result' => $result],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 0;
        } catch (Throwable $exception) {
            $code = preg_match('/^OPS_[A-Z0-9_]+$/D', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'OPS_UPGRADE_WORKER_FAILED';
            $output->writeln(json_encode(['ok' => false, 'error_code' => $code], JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    private function service(): UpgradeTaskExecution
    {
        return $this->service
            ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
    }

    private function taskKey(Input $input): string
    {
        $taskKey = trim((string) $input->getOption('task-key'));
        if (preg_match('/^job_[a-f0-9]{32}$/D', $taskKey) !== 1) {
            throw new \InvalidArgumentException('OPS_UPGRADE_TASK_KEY_INVALID');
        }
        return $taskKey;
    }

    private function revision(Input $input): int
    {
        $revision = trim((string) $input->getOption('revision'));
        if (preg_match('/^[1-9][0-9]*$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('OPS_UPGRADE_EXECUTION_REVISION_INVALID');
        }
        return (int) $revision;
    }
}
