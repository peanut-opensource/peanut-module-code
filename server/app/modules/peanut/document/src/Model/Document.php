<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

final class Document extends TenantOwnedModel
{
    protected $name = 'document';
    protected $autoWriteTimestamp = false;
}
