<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

final class DocumentCommentMessage extends TenantOwnedModel
{
    protected $name = 'document_comment_message';
    protected $autoWriteTimestamp = false;

    public static function onBeforeUpdate($model): never
    {
        throw \app\common\exception\BusinessException::conflict('DOCUMENT_COMMENT_HISTORY_IMMUTABLE', '评论活动和请求回执不可改写');
    }
    public static function onBeforeDelete($model): never
    {
        throw \app\common\exception\BusinessException::conflict('DOCUMENT_COMMENT_HISTORY_IMMUTABLE', '评论活动和请求回执不可删除');
    }
}
