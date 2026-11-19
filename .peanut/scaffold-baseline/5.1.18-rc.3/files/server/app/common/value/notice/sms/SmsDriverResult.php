<?php

declare(strict_types=1);

namespace app\common\value\notice\sms;

final readonly class SmsDriverResult
{
    public const OUTCOME_SUCCEEDED = 'succeeded';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_UNKNOWN = 'unknown';

    /** @param array<string, mixed> $receipt */
    public function __construct(
        public string $outcome,
        public string $error,
        public array $receipt,
    ) {
        if (!in_array($outcome, [self::OUTCOME_SUCCEEDED, self::OUTCOME_FAILED, self::OUTCOME_UNKNOWN], true)) {
            throw new \InvalidArgumentException('SMS_DRIVER_OUTCOME_INVALID');
        }
    }

}
