<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property string $content_kind
 * @property string|null $content_snapshot_json
 * @property int|string $id
 * @property string|null $immutable_file_key
 * @property string|null $immutable_file_mime_type
 * @property string|null $immutable_file_sha256
 * @property int|string $revision_no
 * @property string $snapshot_sha256
 * @property int|string $source_state_seq
 * @property string|null $yjs_snapshot
 */
final class DocumentRevision extends TenantOwnedModel
{
    protected $name = 'document_revision';
    protected $autoWriteTimestamp = false;
    public static function onBeforeUpdate(\think\Model $model): void
    {
        throw new \LogicException('DOCUMENT_REVISION_IMMUTABLE');
    }
    public static function onBeforeDelete(\think\Model $model): void
    {
        throw new \LogicException('DOCUMENT_REVISION_IMMUTABLE');
    }
}
