<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

use app\common\model\TenantOwnedModel;

/** The accepted whole-batch intent; storage receipts remain per object. */
final class FileVisibilityBatch extends TenantOwnedModel
{
    protected $name = 'file_visibility_batch';
    protected $pk = 'batch_key';
    protected $autoWriteTimestamp = false;
}
