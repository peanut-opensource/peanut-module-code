<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

use app\common\model\InstanceOwnedModel;

/** Deployment-owned mapping from a business purpose to a storage space. */
final class StorageRoute extends InstanceOwnedModel
{
    protected $name = 'storage_route';
    protected $autoWriteTimestamp = false;
}
