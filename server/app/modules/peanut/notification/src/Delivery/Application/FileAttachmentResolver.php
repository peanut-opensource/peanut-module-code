<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Application;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Contract\FileAccess;

/** File owns readiness, source bytes and current preview authorization. No URL or original download is retained. */
final readonly class FileAttachmentResolver implements AttachmentResolver
{
    public function __construct(private FileAccess $files) {}

    public function snapshot(TenantContext $context, string $fileKey): AttachmentReference
    {
        $source = $this->files->attachmentSnapshot($context, $fileKey);
        if (($source['file_key'] ?? null) !== $fileKey || !is_string($source['original_name'] ?? null)
            || !is_string($source['verified_mime_type'] ?? null) || !is_int($source['size_bytes'] ?? null)
            || !is_string($source['sha256'] ?? null)) {
            throw NotificationException::attachmentUnavailable();
        }
        return new AttachmentReference($fileKey, $source['original_name'], $source['verified_mime_type'], $source['size_bytes'], $source['sha256']);
    }
}
