<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

use app\common\model\TenantOwnedModel;

final class FileReference extends TenantOwnedModel
{
    protected $name = 'file_reference';
    protected $autoWriteTimestamp = false;
}
