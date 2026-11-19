<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

use app\common\model\TenantOwnedModel;

/** Access uses the complete (batch_key,file_key) key, never a model save by a partial key. */
final class FileVisibilityItem extends TenantOwnedModel
{
    protected $name = 'file_visibility_item';
    protected $pk = ['batch_key', 'file_key'];
    protected $autoWriteTimestamp = false;
}
