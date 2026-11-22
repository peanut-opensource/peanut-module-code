<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property string $persisted_at
 * @property string $projected_json
 * @property string $projection_schema_version
 * @property int|string $state_seq
 * @property string $state_sha256
 * @property string|null $yjs_state_binary
 */
final class DocumentState extends TenantOwnedModel
{
    protected $name = 'document_state';
    protected $autoWriteTimestamp = false;
}
