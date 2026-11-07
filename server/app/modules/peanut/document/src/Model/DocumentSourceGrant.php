<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

/** Document-owned authority ledger; never exposes session binding through public receipts. */
final class DocumentSourceGrant extends TenantOwnedModel
{
    protected $name = 'document_source_grant';
    protected $autoWriteTimestamp = false;
}
