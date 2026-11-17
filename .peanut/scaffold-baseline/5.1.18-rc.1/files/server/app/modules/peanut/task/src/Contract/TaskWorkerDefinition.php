<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** A business Module's handler and its authoritative async reauthorization rule. */
interface TaskWorkerDefinition
{
    public function ownerModuleKey(): string;

    public function resourceKey(): string;

    public function operation(): string;

    /** @return list<TaskHandler> */
    public function handlers(): array;

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext;
}
