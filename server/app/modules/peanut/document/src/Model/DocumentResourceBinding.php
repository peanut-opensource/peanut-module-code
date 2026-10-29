<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

final class DocumentResourceBinding extends TenantOwnedModel
{
    protected $name = 'document_resource_binding';
    protected $autoWriteTimestamp = false;
}
