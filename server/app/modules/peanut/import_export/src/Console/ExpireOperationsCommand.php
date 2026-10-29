<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Console;

use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** 一次显式CLI调用处理一批；不注册HTTP入口或自动调度。 */
final class ExpireOperationsCommand extends ContextualCommand
{
    public static function __make(\think\App $app): self
    {
        return new self(
            $app->make(ImportExportStore::class),
            $app->make(ExecutionContextStore::class),
            $app->make(CurrentExecutionContext::class),
        );
    }

    public function __construct(
        private readonly ?ImportExportStore $store = null,
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $execution = null,
    )
    {
        parent::__construct($contexts, $execution);
    }

    protected function configure(): void
    {
        $this->setName('import-export:expire')->setDescription('平台维护：将到期终态导入导出任务标为expired，仅清文件引用')
            ->addOption('limit', null, Option::VALUE_REQUIRED, '单批记录数，1–1000', '100');
    }

    protected function handle(Input $input, Output $output): int
    {
        if (!$this->establishedInstanceContext()) {
            throw new \DomainException('PLATFORM_MAINTENANCE_CONTEXT_REQUIRED');
        }
        $limit = $input->getOption('limit');
        if (!is_string($limit) || preg_match('/^[1-9][0-9]{0,3}$/D', $limit) !== 1 || (int) $limit > 1000) {
            throw new \InvalidArgumentException('IMPORT_EXPORT_RETENTION_BATCH_INVALID');
        }
        $expired = ($this->store ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED'))->expireDue((int) $limit);
        $output->writeln(json_encode(['expired' => $expired, 'batch_limit' => (int) $limit], JSON_THROW_ON_ERROR));
        return 0;
    }
}
