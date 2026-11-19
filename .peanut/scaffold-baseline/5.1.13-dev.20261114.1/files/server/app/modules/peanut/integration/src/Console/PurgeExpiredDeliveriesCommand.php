<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Console;

use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\Integration\Infrastructure\Persistence\ThinkPhpIntegrationSecurityRepository;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** 一次CLI调用只提交一批；相同截止值重复调用继续，返回全零表示当前无可处理记录。 */
final class PurgeExpiredDeliveriesCommand extends ContextualCommand
{
    public static function __make(\think\App $app): self
    {
        return new self(
            $app->make(ThinkPhpIntegrationSecurityRepository::class),
            $app->make(ExecutionContextStore::class),
            $app->make(CurrentExecutionContext::class),
        );
    }

    public function __construct(
        private readonly ?ThinkPhpIntegrationSecurityRepository $store = null,
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $execution = null,
    )
    {
        parent::__construct($contexts, $execution);
    }

    protected function configure(): void
    {
        $this->setName('integration:purge-expired')->setDescription('平台维护：分批清理到期Webhook载荷、尝试和投递记录')
            ->addOption('payload-cutoff', null, Option::VALUE_REQUIRED, '载荷截止UTC时间，RFC3339，支持毫秒')
            ->addOption('evidence-cutoff', null, Option::VALUE_REQUIRED, '证据截止UTC时间，RFC3339，支持毫秒')
            ->addOption('limit', null, Option::VALUE_REQUIRED, '载荷、尝试、投递各自的单批上限，1–1000', '100');
    }

    protected function handle(Input $input, Output $output): int
    {
        if (!$this->establishedInstanceContext()) {
            throw new \DomainException('PLATFORM_MAINTENANCE_CONTEXT_REQUIRED');
        }
        $limit = $input->getOption('limit');
        if (!is_string($limit) || preg_match('/^[1-9][0-9]{0,3}$/D', $limit) !== 1 || (int) $limit > 1000) {
            throw new \InvalidArgumentException('INTEGRATION_RETENTION_BATCH_INVALID');
        }
        $result = ($this->store ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED'))->purgeExpiredDeliveryData(
            $this->instant($input->getOption('payload-cutoff')),
            $this->instant($input->getOption('evidence-cutoff')),
            (int) $limit,
        );
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
        return 0;
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{3})?Z$/D', $value) !== 1) {
            throw new \InvalidArgumentException('INTEGRATION_RETENTION_CUTOFF_INVALID');
        }
        $format = str_contains($value, '.') ? 'Y-m-d\TH:i:s.v\Z' : 'Y-m-d\TH:i:s\Z';
        $instant = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        if (!$instant instanceof DateTimeImmutable || $instant->format($format) !== $value) {
            throw new \InvalidArgumentException('INTEGRATION_RETENTION_CUTOFF_INVALID');
        }
        return $instant;
    }
}
