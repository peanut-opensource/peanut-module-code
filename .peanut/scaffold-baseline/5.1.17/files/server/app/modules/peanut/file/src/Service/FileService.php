<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** Business file references enter the physical storage layer only here. */
final readonly class FileService implements FileReferences
{
    public function __construct(
        private StorageService $storage,
        private string $applicationOrigin,
    ) {}

    public function getFileUrl(string $reference = ''): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        return $this->storage->publicUrl($reference);
    }

    public function setTenantFileUrl(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $value = '',
    ): string {
        return $this->storage->normalizePublicReference($context->tenantId, $value);
    }
}
