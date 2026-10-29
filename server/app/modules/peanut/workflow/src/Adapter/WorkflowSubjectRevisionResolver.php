<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** Public SPI implemented by a subject-owning Module; it exposes no subject persistence model. */
interface WorkflowSubjectRevisionResolver
{
    /** @return array{revision_key: string, sha256: string} */
    public function resolve(
        AuthorizedOperationContext $context,
        string $subjectType,
        string $subjectKey,
        string $expectedRevisionKey,
    ): array;
}
