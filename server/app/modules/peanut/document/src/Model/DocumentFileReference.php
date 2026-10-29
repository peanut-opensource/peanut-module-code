<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

final class DocumentFileReference extends TenantOwnedModel
{
    protected $name = 'document_file_ref';
    protected $autoWriteTimestamp = false;
}
