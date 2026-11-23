<?php

declare(strict_types=1);

namespace app\command;

use PeanutAdmin\Modules\Ops\Contract\DeploymentModuleRequests;
use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Throwable;

/** Deployment-only preparation of an opaque Module operation request. */
final class OpsModuleRequest extends ContextualCommand
{
    public static function __make(\think\App $app): self
    {
        return new self($app->make(ExecutionContextStore::class), $app->make(CurrentExecutionContext::class),
            $app->make(DeploymentModuleRequests::class));
    }

    public function __construct(
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $executionContext = null,
        private readonly ?DeploymentModuleRequests $requests = null,
    ) {
        parent::__construct($contexts, $executionContext);
    }

    protected function configure(): void
    {
        $this->setName('ops-module:request')
            ->addArgument('action', Argument::REQUIRED, 'preview or prepare')
            ->addOption('delivery-resource-id', null, Option::VALUE_REQUIRED, 'Registered delivery worker resource')
            ->addOption('target-resource-id', null, Option::VALUE_REQUIRED, 'Registered deployment target')
            ->addOption('operation', null, Option::VALUE_REQUIRED, 'install, update, retire, or purge')
            ->addOption('package-key', null, Option::VALUE_REQUIRED, 'Exact Package key')
            ->addOption('archive-sha256', null, Option::VALUE_OPTIONAL, 'Trusted inbox archive SHA-256', '')
            ->addOption('signature-key-id', null, Option::VALUE_OPTIONAL, 'Trusted signing key id', '')
            ->addOption('confirm-plan-digest', null, Option::VALUE_OPTIONAL, 'Exact retire/purge preview digest', '')
            ->setDescription('Preview or stage one registry-bound Module delivery request');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $service = $this->requests
                ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
            $arguments = [
                trim((string) $input->getOption('delivery-resource-id')),
                trim((string) $input->getOption('target-resource-id')),
                trim((string) $input->getOption('operation')),
                trim((string) $input->getOption('package-key')),
                ($sha = trim((string) $input->getOption('archive-sha256'))) === '' ? null : $sha,
                ($key = trim((string) $input->getOption('signature-key-id'))) === '' ? null : $key,
            ];
            $action = trim((string) $input->getArgument('action'));
            $prepareArguments = [
                ...$arguments,
                ($digest = trim((string) $input->getOption('confirm-plan-digest'))) === '' ? null : $digest,
            ];
            $result = match ($action) {
                'preview' => $service->preview(...$arguments),
                'prepare' => $service->prepare(...$prepareArguments),
                default => throw new \RuntimeException('OPS_MODULE_REQUEST_ACTION_INVALID'),
            };
            $output->writeln(json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (Throwable $exception) {
            $code = preg_match('/^(OPS|PLUGIN|MODULE|PACKAGE)_[A-Z0-9_]+$/D', $exception->getMessage()) === 1
                ? $exception->getMessage() : 'OPS_MODULE_REQUEST_FAILED';
            $output->writeln(json_encode(['ok' => false, 'error_code' => $code], JSON_THROW_ON_ERROR));
            return 1;
        }
    }
}
