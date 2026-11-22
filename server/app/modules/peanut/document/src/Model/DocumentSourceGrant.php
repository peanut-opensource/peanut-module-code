<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

/** Document-owned authority ledger; never exposes session binding through public receipts. *
 * @property string $binding_json
 * @property int|string $document_id
 * @property int|string $expires_at
 * @property string $grant_key
 * @property int|string $revision_id
 * @property int|string|null $revoked_at
 * @property string $version_digest
 */
final class DocumentSourceGrant extends TenantOwnedModel
{
    protected $name = 'document_source_grant';
    protected $autoWriteTimestamp = false;
}
