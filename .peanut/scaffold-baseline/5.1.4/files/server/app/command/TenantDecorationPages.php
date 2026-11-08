<?php

declare(strict_types=1);

namespace app\command;

use app\common\enum\decoration\DecorationEnum;
use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\services\decoration\DecorationPageDefaultsService;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** Instance maintenance only; not an HTTP or tenant-scheduler entrypoint. */
final class TenantDecorationPages extends ContextualCommand
{
    public static function __make(\think\App $app): self
    {
        return new self(
            $app->make(DecorationPageDefaultsService::class),
            $app->make(TenantModuleStateQueries::class),
            $app->make(ExecutionContextStore::class),
            $app->make(CurrentExecutionContext::class),
        );
    }

    public function __construct(
        private readonly ?DecorationPageDefaultsService $pages = null,
        private readonly ?TenantModuleStateQueries $tenants = null,
        private readonly ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $execution = null,
    ) {
        parent::__construct($contexts, $execution);
    }

    protected function configure(): void
    {
        $this->setName('tenant:decoration-pages')->setDescription('Insert only missing default pages for one existing tenant')
            ->addOption('tenant-id', null, Option::VALUE_REQUIRED, 'Explicit positive tenant ID')
            ->addOption('types', null, Option::VALUE_REQUIRED, 'Comma-separated declared page types', implode(',', DecorationEnum::ALL_TYPES))
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Preview missing types without writing');
    }

    protected function handle(Input $input, Output $output): int
    {
        if (PHP_SAPI !== 'cli' || $this->executionContext()->current() !== null) {
            throw new \DomainException('PLATFORM_MAINTENANCE_CONTEXT_REQUIRED');
        }
        $tenantId = $input->getOption('tenant-id');
        if (!is_string($tenantId) || preg_match('/^[1-9][0-9]*$/D', $tenantId) !== 1
            || filter_var($tenantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('DECORATION_TENANT_INVALID');
        }
        $types = $input->getOption('types');
        if (!is_string($types) || preg_match('/^[1-5](,[1-5])*$/D', $types) !== 1) {
            throw new \InvalidArgumentException('DECORATION_PAGE_TYPES_INVALID');
        }
        $pages = $this->pages ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
        $tenants = $this->tenants ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
        if (!$tenants->tenantIsActive((int) $tenantId)) {
            throw new \DomainException('DECORATION_ACTIVE_TENANT_REQUIRED');
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $context = new TenantSystemContext(
            (int) $tenantId, 'console.tenant:decoration-pages', 'decoration.complete-pages',
            'cli-' . getmypid() . '-' . bin2hex(random_bytes(8)),
        );
        $result = $this->contexts->run(new SystemExecutionContext($context),
            fn() => $pages->ensureMissing(array_map('intval', explode(',', $types)), $dryRun));
        $output->writeln(json_encode(['tenant_id' => (int) $tenantId, 'dry_run' => $dryRun] + $result, JSON_THROW_ON_ERROR));
        return 0;
    }

    protected function establishesInstanceContext(): bool
    {
        // Like the tenant worker, this entrypoint establishes a tenant system root directly.
        return false;
    }
}
