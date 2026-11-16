<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Whole-revision candidates; discussion resolution and suggestion decisions are independent. */
interface DocumentRevisionSuggestions
{
    public function createSuggestion(TenantContext $context, int $documentId, int $revisionId, array $input): array;
    public function acceptSuggestion(TenantContext $context, int $documentId, int $revisionId, array $input): array;
    public function rejectSuggestion(TenantContext $context, int $documentId, int $revisionId, array $input): array;
}
