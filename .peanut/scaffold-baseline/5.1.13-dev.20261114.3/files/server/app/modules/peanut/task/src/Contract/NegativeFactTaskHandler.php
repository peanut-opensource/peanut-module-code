<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use PeanutAdmin\Modules\Task\Job\Execution\NegativeFactJobEnvelope;

/** Only the registered Identity negative-fact purpose, never ordinary member authorization. */
interface NegativeFactTaskHandler extends TaskHandler
{
    public function validateNegativeFact(NegativeFactJobEnvelope $envelope): void;
    public function handleNegativeFact(NegativeFactJobEnvelope $envelope, JobExecution $execution): void;
    public function negativeSubmission(array $fact): TaskSubmission;
}
