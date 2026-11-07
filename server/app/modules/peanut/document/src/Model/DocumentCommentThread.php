<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

final class DocumentCommentThread extends TenantOwnedModel
{
    protected $name = 'document_comment_thread';
    protected $autoWriteTimestamp = false;
}
