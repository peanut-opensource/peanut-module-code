<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ContextualCommand;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\infrastructure\runtime\OperationalLog;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;

/** Native installer consumes only the selected module's fixed owned resource. */
final class WorkflowInstallManaged extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('workflow:install-managed')->setDescription('Install or upgrade an owned Workflow template using a current authorized tenant session')
            ->addArgument('module', Argument::REQUIRED)->addArgument('workflow', Argument::REQUIRED)
            ->addOption('manifest-sha256', null, Option::VALUE_REQUIRED)
            ->addOption('bindings', null, Option::VALUE_REQUIRED, 'Absolute reviewed organization binding JSON file')
            ->addOption('token-file', null, Option::VALUE_REQUIRED, 'Absolute owner-only session credential file')
            ->addOption('idempotency-key', null, Option::VALUE_REQUIRED);
    }

    /** The current human session establishes the sole Admin root, without an Instance audience. */
    protected function establishesInstanceContext(): bool
    {
        return false;
    }

    protected function handle(Input $input, Output $output): int
    {
        $requestId = null;
        try {
            if (PHP_SAPI !== 'cli' || $this->executionContext()->current() !== null) {
                throw new \DomainException('WORKFLOW_NATIVE_INSTALL_REQUIRED');
            }
            $tokenPath = (string) $input->getOption('token-file');
            $bindingsPath = (string) $input->getOption('bindings');
            foreach ([$tokenPath, $bindingsPath] as $path) {
                if (!str_starts_with($path, '/') || !is_file($path) || is_link($path)) {
                    throw new \DomainException('WORKFLOW_INSTALL_INPUT_INVALID');
                }
            }
            if ((fileperms($tokenPath) & 0077) !== 0) {
                throw new \DomainException('WORKFLOW_TOKEN_FILE_PERMISSION_INVALID');
            }
            $app = $this->getApp();
            $requestId = 'workflow-install-' . bin2hex(random_bytes(12));
            $tenant = $app->make(\PeanutAdmin\Modules\Identity\Auth\TenantAuthService::class)->context(trim((string) file_get_contents($tokenPath)), $requestId);
            $principal = $app->make(AdminAuthorizationQuery::class)->principal($tenant)->toArray();
            $bindings = json_decode((string) file_get_contents($bindingsPath), true, 128, JSON_THROW_ON_ERROR);
            $result = $app->make(\app\common\execution\ExecutionContextStore::class)->run(new \app\common\execution\AdminExecutionContext($tenant, 'console.workflow:install-managed', $principal), function () use ($app, $input, $bindings) {
                $context = $app->make(\PeanutAdmin\Modules\Workflow\Http\WorkflowHttpContext::class)->operation('peanut.workflow.managed.install', 'peanut.workflow.managed-definition', 'install');
                return $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowManagedDefinitions::class)->install($context, (string) $input->getArgument('module'), (string) $input->getArgument('workflow'), (string) $input->getOption('manifest-sha256'), $bindings, (string) $input->getOption('idempotency-key'));
            });
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));
            return 0;
        } catch (\Throwable $exception) {
            $code = $exception instanceof \PeanutAdmin\Modules\Workflow\Application\WorkflowException ? $exception->errorCode : 'WORKFLOW_INSTALL_FAILED';
            $failures = [];
            $failure = $exception;
            $root = realpath(dirname(__DIR__, 2));
            while ($failure !== null && count($failures) < 8) {
                $exceptionCode = (string) $failure->getCode();
                $file = realpath($failure->getFile());
                $failures[] = [
                    'exception_class' => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', str_replace('\\', '', $failure::class)) === 1 ? $failure::class : 'unavailable',
                    'exception_code' => preg_match('/^[A-Za-z0-9_-]{0,32}$/D', $exceptionCode) === 1 ? $exceptionCode : 'unavailable',
                    'exception_file' => is_string($root) && is_string($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
                        ? substr($file, strlen($root) + 1) : 'unavailable',
                    'exception_line' => $failure->getLine(),
                ];
                $failure = $failure->getPrevious();
            }
            $frames = $exception->getTrace();
            $trace = [];
            foreach (array_slice($frames, 0, 8) as $frame) {
                $file = is_string($frame['file'] ?? null) ? realpath($frame['file']) : false;
                $class = $frame['class'] ?? null;
                $function = $frame['function'] ?? null;
                $trace[] = [
                    'class' => is_string($class) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', str_replace('\\', '', $class)) === 1 ? $class : null,
                    'function' => is_string($function) && ($function === '{closure}' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $function) === 1) ? $function : null,
                    'file' => is_string($root) && is_string($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
                        ? substr($file, strlen($root) + 1) : 'unavailable',
                    'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
                ];
            }
            OperationalLog::error($this->executionContext(), 'workflow_managed_install_failed', [
                'request_id' => $requestId,
                'failure_code' => $code,
                'exception_chain' => $failures,
                'exception_chain_truncated' => $failure !== null,
                'exception_trace' => $trace,
                'exception_trace_truncated' => count($frames) > 8,
            ]);
            $output->writeln(json_encode(['error' => $code], JSON_THROW_ON_ERROR));
            return 1;
        }
    }
}
