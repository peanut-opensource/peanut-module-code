<?php

declare(strict_types=1);

namespace app\command;

use PeanutAdmin\Modules\Ops\Contract\BackupTaskExecution;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\ContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/** Deployment-only bridge for claiming and finalizing trusted backup tasks. */
final class OpsBackupTask extends ContextualCommand
{
    public function __construct(
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $executionContext = null,
        private readonly ?BackupTaskExecution $service = null,
    ) {
        parent::__construct($contexts, $executionContext);
    }

    protected function configure(): void
    {
        $this->setName('ops-backup:task')
            ->addArgument('action', Argument::REQUIRED, 'claim, heartbeat, succeed, or fail')
            ->addOption('task-key', null, Option::VALUE_OPTIONAL, 'Opaque task key')
            ->addOption('revision', null, Option::VALUE_OPTIONAL, 'Claimed execution revision')
            ->addOption('error-code', null, Option::VALUE_OPTIONAL, 'Stable allowlisted failure code')
            ->setDescription('Claim or finalize one trusted paired-backup task');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $service = $this->service
                ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
            $action = trim((string) $input->getArgument('action'));
            if ($action === 'claim') {
                require_once dirname(__DIR__, 2) . '/database/environment-guard.php';
                if (!\registeredMysqlConnectionMatches(\guardedDatabaseConfig())) {
                    throw new \RuntimeException('OPS_BACKUP_APPLICATION_CONNECTION_MISMATCH');
                }
            }
            $result = match ($action) {
                'claim' => $service->claim(),
                'heartbeat' => $service->heartbeat(
                    trim((string) $input->getOption('task-key')),
                    $this->executionRevision($input),
                ),
                'succeed' => $service->succeed(
                    trim((string) $input->getOption('task-key')),
                    $this->executionRevision($input),
                    $this->manifestFromStdin(),
                ),
                'fail' => $service->fail(
                    trim((string) $input->getOption('task-key')),
                    $this->executionRevision($input),
                    trim((string) $input->getOption('error-code')),
                ),
                default => throw new \InvalidArgumentException('OPS_BACKUP_ACTION_INVALID'),
            };
            $output->writeln(json_encode(
                ['ok' => true, 'result' => $result],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 0;
        } catch (Throwable $exception) {
            $code = preg_match('/^OPS_[A-Z0-9_]+$/D', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'OPS_BACKUP_WORKER_FAILED';
            $output->writeln(json_encode(['ok' => false, 'error_code' => $code], JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    private function manifestFromStdin(): string
    {
        $contents = file_get_contents('php://stdin');
        if (!is_string($contents) || $contents === '' || strlen($contents) > 65536) {
            throw new \InvalidArgumentException('OPS_BACKUP_MANIFEST_INVALID');
        }
        return $contents;
    }

    private function executionRevision(Input $input): int
    {
        $revision = trim((string) $input->getOption('revision'));
        if (preg_match('/^[1-9][0-9]*$/D', $revision) !== 1) {
            throw new \InvalidArgumentException('OPS_BACKUP_EXECUTION_REVISION_INVALID');
        }
        return (int) $revision;
    }
}
