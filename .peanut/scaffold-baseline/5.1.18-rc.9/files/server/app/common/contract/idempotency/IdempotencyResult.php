<?php

declare(strict_types=1);

namespace app\common\contract\idempotency;

use LogicException;
use PeanutAdmin\Kernel\Idempotency\IdempotencyRecord;

final readonly class IdempotencyResult
{
    public const EXECUTION = 'execution';
    public const REPLAY = 'replay';
    public const PROCESSING = 'processing';

    private function __construct(
        private IdempotencyRecord $record,
        private int $tenantId,
        public string $state,
    ) {}

    public static function fromRecord(IdempotencyRecord $record, int $tenantId): self
    {
        $state = $record->acquiredForExecution()
            ? self::EXECUTION
            : ($record->replayable() ? self::REPLAY : self::PROCESSING);

        return new self($record, $tenantId, $state);
    }

    public function isExecutionOwner(): bool
    {
        return $this->state === self::EXECUTION;
    }

    public function isReplayable(): bool
    {
        return $this->state === self::REPLAY;
    }

    public function id(): int
    {
        return $this->record->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    /** @return array<string, mixed> */
    public function responseBody(): array
    {
        if (!$this->isReplayable() || $this->record->responseBody === null) {
            throw new LogicException('Idempotency result has no replay body.');
        }

        return $this->record->responseBody;
    }

    public function responseStatus(): int
    {
        if (!$this->isReplayable() || $this->record->responseStatus === null) {
            throw new LogicException('Idempotency result has no replay status.');
        }

        return $this->record->responseStatus;
    }
}
