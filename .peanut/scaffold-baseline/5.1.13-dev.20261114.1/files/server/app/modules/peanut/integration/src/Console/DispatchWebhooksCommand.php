<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Console;

use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Integration\Webhook\WebhookDispatcher;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** One instance-maintenance invocation dispatches a bounded batch for one tenant. */
final class DispatchWebhooksCommand extends ContextualCommand
{
    public static function __make(\think\App $app): self
    {
        return new self(
            $app->make(WebhookDispatcher::class),
            $app->make(ExecutionContextStore::class),
            $app->make(CurrentExecutionContext::class),
        );
    }

    public function __construct(
        private readonly ?WebhookDispatcher $dispatcher = null,
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $execution = null,
    )
    {
        parent::__construct($contexts, $execution);
    }

    protected function configure(): void
    {
        $this->setName('integration:webhook:dispatch')->setDescription('平台维护：分批投递指定租户的待处理Webhook')
            ->addOption('tenant-id', null, Option::VALUE_REQUIRED, '显式指定正整数租户ID')
            ->addOption('limit', null, Option::VALUE_REQUIRED, '本次最多处理的投递数，1–1000', '100');
    }

    protected function handle(Input $input, Output $output): int
    {
        if (!$this->establishedInstanceContext()) {
            throw new \DomainException('PLATFORM_MAINTENANCE_CONTEXT_REQUIRED');
        }
        $tenantId = $input->getOption('tenant-id');
        if (!is_string($tenantId) || preg_match('/^[1-9][0-9]*$/D', $tenantId) !== 1
            || filter_var($tenantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('INTEGRATION_WEBHOOK_TENANT_INVALID');
        }
        $limit = $input->getOption('limit');
        if (!is_string($limit) || preg_match('/^[1-9][0-9]{0,3}$/D', $limit) !== 1 || (int) $limit > 1000) {
            throw new \InvalidArgumentException('INTEGRATION_WEBHOOK_BATCH_INVALID');
        }
        $dispatcher = $this->dispatcher ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
        $processed = 0;
        while ($processed < (int) $limit && $dispatcher->runOne((int) $tenantId)) {
            $processed++;
        }
        $output->writeln(json_encode(['processed' => $processed], JSON_THROW_ON_ERROR));
        return 0;
    }
}
