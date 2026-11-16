<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

use app\common\model\TenantOwnedModel;

final class FileImageAsset extends TenantOwnedModel
{
    protected $name = 'file_image_asset';
    protected $pk = 'file_key';
    protected $autoWriteTimestamp = false;
}
