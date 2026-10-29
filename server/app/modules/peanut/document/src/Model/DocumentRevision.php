<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

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
