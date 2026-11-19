<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

use app\common\model\TenantOwnedModel;

final class FileDerivative extends TenantOwnedModel
{
    protected $name = 'file_derivative';
    protected $pk = 'variant_key';
    protected $autoWriteTimestamp = false;
}
