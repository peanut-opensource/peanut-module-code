<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

use app\common\model\TenantOwnedModel;

/** Tenant-owned storage ledger entry for one durable file object. */
final class FileObject extends TenantOwnedModel
{
    protected $name = 'file_object';
    protected $autoWriteTimestamp = false;
}
