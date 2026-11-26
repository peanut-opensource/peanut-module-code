<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use app\common\exception\BusinessException;
use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Media\ImageMetadataInspector;
use PeanutAdmin\Modules\File\Contract\Dto\UploadFile;

/**
 * File's material-image upload accepts more originals than Core's JPEG/PNG
 * variant contract. Keep output variants unchanged and validate the upload
 * extension, sniffed format and bounded dimensions before any storage write.
 */
final readonly class FileUploadImageInspector
{
    /** @var array<string, list<string>> */
    private const MEDIA_BY_EXTENSION = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'ico' => ['image/vnd.microsoft.icon', 'image/x-icon'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
    ];

    /** @return array{width:int,height:int,media_type:string} */
    public function inspect(UploadFile $uploaded): array
    {
        $allowed = self::MEDIA_BY_EXTENSION[strtolower($uploaded->extension)] ?? null;
        if ($allowed === null || is_link($uploaded->path) || !is_file($uploaded->path) || !is_readable($uploaded->path)) {
            throw $this->invalid();
        }
        $size = @filesize($uploaded->path);
        if (!is_int($size) || $size < 1 || $size !== $uploaded->size || $size > \PeanutAdmin\Modules\File\Enum\FileEnum::MAX_SIZE[\PeanutAdmin\Modules\File\Enum\FileEnum::IMAGE]) {
            throw $this->invalid();
        }

        $raw = @getimagesize($uploaded->path);
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($uploaded->path);
        if ($raw === false || !is_string($raw['mime'] ?? null) || !is_string($detected)
            || !in_array($raw['mime'], $allowed, true) || !in_array($detected, $allowed, true)
            || $raw[0] < 1 || $raw[1] < 1 || $raw[0] > 50000 || $raw[1] > 50000
            || $raw[0] * $raw[1] > 100_000_000
        ) {
            throw $this->invalid();
        }

        if (in_array($raw['mime'], ['image/jpeg', 'image/png'], true)) {
            try {
                // Retain Core's unchanged strict JPEG/PNG metadata contract.
                return (new ImageMetadataInspector(\PeanutAdmin\Modules\File\Enum\FileEnum::MAX_SIZE[\PeanutAdmin\Modules\File\Enum\FileEnum::IMAGE]))
                    ->inspect($uploaded->path)->toArray();
            } catch (FileMediaException) {
                throw $this->invalid();
            }
        }

        return ['width' => (int) $raw[0], 'height' => (int) $raw[1], 'media_type' => $raw['mime']];
    }

    private function invalid(): BusinessException
    {
        return BusinessException::invalid('FILE_IMAGE_INVALID', '图片内容无效、格式与扩展名不一致，或超出尺寸限制');
    }
}

