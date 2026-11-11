<?php

declare(strict_types=1);

namespace app\common\validation\instance;

use app\common\enum\instance\DeploymentMode;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\execution\PlatformExecutionContext;

final class InstanceToolAccessGuard
{
    private function __construct(private readonly ?DeploymentMode $mode) {}

    public static function fromConfiguredValue(mixed $value): self
    {
        return new self(DeploymentMode::fromConfiguredValue($value));
    }

    public function allows(): bool
    {
        return $this->mode === DeploymentMode::Standalone;
    }

    /** Exact Platform Module routes only; the middleware also enforces their existing permissions. */
    public function platformModulePermission(CurrentExecutionContext $executionContext): ?string
    {
        $context = $executionContext->current();
        if (!in_array($this->mode, [DeploymentMode::Standalone, DeploymentMode::MultiTenant], true)
            || !$context instanceof PlatformExecutionContext) {
            return null;
        }
        return match ($context->operation()) {
            'http.platform.get.instance-tools/modules' => 'platform.module.read',
            'http.platform.post.instance-tools/modules/create' => 'platform.module.create',
            'http.platform.post.instance-tools/modules/install' => 'platform.module.install',
            'http.platform.post.instance-tools/modules/update' => 'platform.module.update',
            'http.platform.post.instance-tools/modules/uninstall' => 'platform.module.uninstall',
            'http.platform.post.instance-tools/modules/disable' => 'platform.module.disable',
            'http.platform.post.instance-tools/modules/sync' => 'platform.module.sync',
            default => null,
        };
    }

    /**
     * Allows a local development maintainer to mutate instance Module state.
     *
     * The trusted instance actor and operation must come from ContextualCommand;
     * request/person contexts and unknown deployment modes remain denied.
     */
    public function allowsCliDevelopmentMaintenance(
        mixed $environment,
        bool $debug,
        CurrentExecutionContext $executionContext,
        string $commandName,
        bool $commandLifecycleEstablished,
    ): bool {
        $context = $executionContext->current();
        if (!$commandLifecycleEstablished
            || PHP_SAPI !== 'cli'
            || strtolower(trim((string) $environment)) !== 'development'
            || !$debug
            || !in_array($this->mode, [DeploymentMode::Standalone, DeploymentMode::MultiTenant], true)
            || !$context instanceof InstanceExecutionContext) {
            return false;
        }

        $commandName = trim($commandName);
        return $commandName !== ''
            && hash_equals('console.' . $commandName, $context->operation())
            && hash_equals('console', $context->instance->actorKey);
    }
}
