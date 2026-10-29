<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Ops\Contract\ModuleTaskExecution;
use app\common\execution\ContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Throwable;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\exception\plugin\PluginLifecycleException;

/** Deployment-control bridge for one fixed Module operation task. */
final class OpsModuleTask extends ContextualCommand
{
    public static function __make(\think\App $app): self
    {
        return new self($app->make(ExecutionContextStore::class), $app->make(CurrentExecutionContext::class),
            $app->make(ModuleTaskExecution::class));
    }

    public function __construct(
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $executionContext = null,
        private readonly ?ModuleTaskExecution $service = null,
    ) {
        parent::__construct($contexts, $executionContext);
    }

    protected function configure(): void
    {
        $this->setName('ops-module:task')
            ->addArgument('action', Argument::REQUIRED, 'work, claim, advance, execute, heartbeat, succeed, or fail')
            ->addOption('once', null, Option::VALUE_NONE, 'Consume one configured local development job')
            ->addOption('task-key', null, Option::VALUE_OPTIONAL, 'Opaque task key')
            ->addOption('revision', null, Option::VALUE_OPTIONAL, 'Claimed execution revision')
            ->addOption('error-code', null, Option::VALUE_OPTIONAL, 'Stable allowlisted failure code')
            ->setDescription('Advance one deployment-owned Module package operation');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $action = trim((string) $input->getArgument('action'));
            if ($action === 'work') {
                $app = $this->getApp();
                $guard = InstanceToolAccessGuard::fromConfiguredValue($app->config->get('deployment.mode'));
                if (!$input->getOption('once') || !$guard->allowsCliDevelopmentMaintenance(
                    $app->config->get('peanut.environment'), $app->isDebug(), $this->executionContext(),
                    $this->getName(), $this->establishedInstanceContext())) {
                    throw new \RuntimeException('OPS_MODULE_LOCAL_WORKER_UNAVAILABLE');
                }
            }
            $result = match ($action) {
                'work' => $this->service()->work(),
                'claim' => $this->service()->claim(),
                'advance' => $this->service()->advance($this->taskKey($input), $this->revision($input)),
                'execute' => $this->service()->execute($this->taskKey($input), $this->revision($input)),
                'heartbeat' => $this->service()->heartbeat($this->taskKey($input), $this->revision($input)),
                'succeed' => $this->service()->succeed($this->taskKey($input), $this->revision($input)),
                'fail' => $this->service()->fail(
                    $this->taskKey($input),
                    $this->revision($input),
                    trim((string) $input->getOption('error-code')),
                ),
                default => throw new \RuntimeException('OPS_MODULE_TASK_ACTION_INVALID'),
            };
            $output->writeln(json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (Throwable $exception) {
            $code = $exception instanceof PluginPackageException || $exception instanceof PluginLifecycleException
                ? $exception->errorCode
                : (preg_match('/^(OPS|PLUGIN|MODULE|PACKAGE)_[A-Z0-9_]+$/D', $exception->getMessage()) === 1
                    ? $exception->getMessage() : 'OPS_MODULE_WORKER_FAILED');
            $exit = $exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1;
            $cause = $exception;
            while ($cause->getPrevious() instanceof PluginPackageException
                || $cause->getPrevious() instanceof PluginLifecycleException) {
                $cause = $cause->getPrevious();
            }
            if ($cause->getCode() > 0 && $cause->getCode() < 256) {
                $exit = $cause->getCode();
            }
            $output->writeln(json_encode(['ok' => false, 'error_code' => $code, 'exit_code' => $exit]
                + ($exception instanceof PluginPackageException ? ['reason' => $exception->getMessage(), 'details' => $exception->details] : []),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $exit;
        }
    }

    private function service(): ModuleTaskExecution
    {
        return $this->service
            ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
    }

    private function taskKey(Input $input): string
    {
        $key = trim((string) $input->getOption('task-key'));
        if (preg_match('/^job_[a-f0-9]{32}$/D', $key) !== 1) {
            throw new \RuntimeException('OPS_MODULE_TASK_KEY_INVALID');
        }
        return $key;
    }

    private function revision(Input $input): int
    {
        $revision = trim((string) $input->getOption('revision'));
        if (preg_match('/^[1-9][0-9]*$/D', $revision) !== 1) {
            throw new \RuntimeException('OPS_MODULE_EXECUTION_REVISION_INVALID');
        }
        return (int) $revision;
    }
}
