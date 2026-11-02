<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

/** Generic file uploads, including original audio; media workflows belong to the consumer. */
final class UploadExtensions
{
    public const FILE = ['zip', 'rar', 'txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', '7z', 'gz', 'mp3', 'wav', 'ogg'];
}
