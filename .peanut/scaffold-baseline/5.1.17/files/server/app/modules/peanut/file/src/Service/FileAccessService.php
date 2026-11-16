<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Contract\FileAccess;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;

final readonly class FileAccessService implements FileAccess
{
    public function __construct(private StorageService $storage) {}

    public function resolve(TenantContext $context, string $fileKey, string $operation, ?int $documentId = null, ?string $variantKey = null, ?string $viewerAdapterId = null): array
    {
        return $this->storage->resolveAuthorized($context, $fileKey, $operation, $documentId, $variantKey, $viewerAdapterId);
    }

    public function resolveMany(TenantContext $context, array $fileKeys, string $operation): array
    {
        if (count($fileKeys) > 100 || $fileKeys !== array_values($fileKeys)) {
            throw new \InvalidArgumentException('FILE_ACCESS_SET_INVALID');
        }
        return array_map(fn(string $key): array => $this->resolve($context, $key, $operation), $fileKeys);
    }

    public function attachmentSnapshot(TenantContext $context, string $fileKey): array
    {
        return $this->storage->attachmentSnapshot($context, $fileKey);
    }
}
