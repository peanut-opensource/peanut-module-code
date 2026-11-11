<?php

declare(strict_types=1);

namespace app\common\execution;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * Establishes one immutable execution context around every top-level CLI command.
 * ThinkPHP's list descriptor constructs command metadata without the container:
 * subclasses must allow zero-argument construction, while execution still
 * requires the injected context and business dependencies.
 */
abstract class ContextualCommand extends Command
{
    private bool $establishedInstanceContext = false;

    public function __construct(
        private readonly ?ExecutionContextStore $contexts = null,
        private readonly ?CurrentExecutionContext $executionContext = null,
    ) {
        parent::__construct();
    }

    final protected function execute(Input $input, Output $output): int
    {
        if ($this->contexts === null || $this->executionContext === null) {
            throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
        }
        if ($this->contexts->current() !== null) {
            return $this->handle($input, $output);
        }

        try {
            if (!$this->establishesInstanceContext()) {
                return $this->handle($input, $output);
            }
            return $this->contexts->run(
                new InstanceExecutionContext(
                    'console.' . $this->getName(),
                    'cli-' . getmypid() . '-' . bin2hex(random_bytes(8)),
                ),
                function () use ($input, $output): int {
                    $this->establishedInstanceContext = true;
                    try {
                        return $this->handle($input, $output);
                    } finally {
                        $this->establishedInstanceContext = false;
                    }
                },
            );
        } finally {
            if (!$this->contexts->isEmpty()) {
                error_log('[ContextualCommand] execution context stack not empty after ' . $this->getName());
            }
        }
    }

    final protected function executionContext(): CurrentExecutionContext
    {
        return $this->executionContext
            ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
    }

    /** True only while this command's execute() owns the current instance context. */
    final protected function establishedInstanceContext(): bool
    {
        return $this->establishedInstanceContext;
    }

    /** Tenant Task entrypoints establish an authorized root for each job instead. */
    protected function establishesInstanceContext(): bool
    {
        return true;
    }

    abstract protected function handle(Input $input, Output $output): int;
}
