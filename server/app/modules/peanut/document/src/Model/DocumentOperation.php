<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

final class DocumentOperation extends TenantOwnedModel
{
    protected $name = 'document_operation';
    protected $autoWriteTimestamp = false;
}
