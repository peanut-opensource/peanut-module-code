<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

use app\common\model\InstanceOwnedModel;

/** Deployment-owned storage provider account configuration. */
final class StorageAccount extends InstanceOwnedModel
{
    protected $name = 'storage_account';
    protected $autoWriteTimestamp = false;
}
