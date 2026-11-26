<?php

declare(strict_types=1);

namespace app\common\contract\idempotency;

interface IdempotentCommandExecutor
{
    public function begin(IdempotencyCommand $command): IdempotencyResult;

    public function complete(IdempotencyResult $execution, IdempotencyReceipt $receipt): void;

    public function fail(IdempotencyResult $execution, IdempotencyReceipt $receipt): void;
}
