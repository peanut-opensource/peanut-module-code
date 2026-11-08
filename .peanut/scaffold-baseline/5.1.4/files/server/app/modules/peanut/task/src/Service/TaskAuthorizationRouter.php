<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Kernel\Async\AsyncAuthorizationRevalidator;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** Routes a signed envelope only to the Module that owns its authorization rule. */
final readonly class TaskAuthorizationRouter implements AsyncAuthorizationRevalidator
{
    /** @var array<string,TaskWorkerDefinition> */
    private array $definitions;

    /** @param iterable<TaskWorkerDefinition> $definitions */
    public function __construct(iterable $definitions)
    {
        $resolved = [];
        foreach ($definitions as $definition) {
            $key = self::key($definition->resourceKey(), $definition->operation());
            if (isset($resolved[$key])) {
                throw new \InvalidArgumentException('TASK_AUTHORIZATION_DUPLICATE');
            }
            $resolved[$key] = $definition;
        }
        if ($resolved === []) {
            throw new \InvalidArgumentException('TASK_WORKER_DEFINITION_REQUIRED');
        }
        $this->definitions = $resolved;
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        return ($this->definitions[self::key($envelope->resourceKey, $envelope->operation)] ?? null)
            ?->reauthorize($envelope)
            ?? throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
    }

    private static function key(string $resourceKey, string $operation): string
    {
        $resourceKey = trim($resourceKey);
        $operation = trim($operation);
        if ($resourceKey === '' || $operation === '') {
            throw new \InvalidArgumentException('TASK_AUTHORIZATION_INVALID');
        }
        return $resourceKey . "\0" . $operation;
    }
}
