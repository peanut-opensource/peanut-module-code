<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Value\Storage;

use PeanutAdmin\Modules\File\Infrastructure\Storage\StorageAccess;

final class StoragePurpose
{
    private const ACCESS = [
        'material.image' => StorageAccess::PUBLIC,
        'material.image-variant' => StorageAccess::PUBLIC,
        'material.video' => StorageAccess::PUBLIC,
        'material.file' => StorageAccess::PUBLIC,
        'member.avatar' => StorageAccess::PUBLIC,
        'article.asset' => StorageAccess::PUBLIC,
        'decoration.asset' => StorageAccess::PUBLIC,
        'website.asset' => StorageAccess::PUBLIC,
        'export.xlsx' => StorageAccess::PRIVATE,
        'export.csv' => StorageAccess::PRIVATE,
        'attachment.sensitive' => StorageAccess::PRIVATE,
        'document.resource' => StorageAccess::PRIVATE,
        'document.snapshot' => StorageAccess::PRIVATE,
        'document.snapshot-sensitive' => StorageAccess::PRIVATE,
    ];

    public static function accessType(string $purpose): string
    {
        $purpose = trim($purpose);
        if (!isset(self::ACCESS[$purpose])) {
            throw new \InvalidArgumentException('文件用途未登记');
        }
        return self::ACCESS[$purpose];
    }

    public static function allowsDocumentPreviewInheritance(string $purpose): bool
    {
        return !in_array($purpose, ['attachment.sensitive','document.snapshot-sensitive'], true);
    }

    public static function disposition(string $purpose): string
    {
        return str_starts_with($purpose, 'export.')
            ? StorageAccess::ATTACHMENT
            : StorageAccess::INLINE;
    }

}
