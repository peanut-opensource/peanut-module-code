<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Contract;

use Custom\Modules\AiAssistant\Service\AiTextGenerator;

final readonly class AiTextGeneration
{
    public function __construct(private AiTextGenerator $generator) {}

    /** Call from the current tenant Admin boundary, outside a transaction. Reuse the original request_id for recovery. */
    public function generateText(array $input): array
    {
        return $this->generator->generate($input);
    }
}
