<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

final class DocumentChange extends TenantOwnedModel
{
    protected $name = 'document_change';
    protected $autoWriteTimestamp = false;
}
