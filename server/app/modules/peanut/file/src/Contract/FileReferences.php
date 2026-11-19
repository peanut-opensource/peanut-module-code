<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface FileReferences
{
    public function getFileUrl(string $reference = ''): string;

    /**
     * Batch-resolves public file references into delivery URLs.
     *
     * @param list<string> $references
     * @return array<string, string> keyed by trimmed reference
     */
    public function getFileUrls(array $references): array;

    public function setTenantFileUrl(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, string $value = ''): string;

    /**
     * Batch-validates and normalizes tenant public file references.
     *
     * @param list<string> $values
     * @return array<string, string> keyed by trimmed value
     */
    public function setTenantFileUrls(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $values): array;
}
