<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property string|null $initiator_json
 * @property int|string $id
 */
final class DocumentOperation extends TenantOwnedModel
{
    protected $name = 'document_operation';
    protected $autoWriteTimestamp = false;
}
