<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Model\Storage;

final class FileImmutableSnapshot extends \app\common\model\TenantOwnedModel
{
    protected $name = 'file_immutable_snapshot';
    protected $pk = 'file_key';
    protected $autoWriteTimestamp = false;
}
