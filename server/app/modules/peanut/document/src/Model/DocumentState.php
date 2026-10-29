<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

final class DocumentState extends TenantOwnedModel
{
    protected $name = 'document_state';
    protected $autoWriteTimestamp = false;
}
