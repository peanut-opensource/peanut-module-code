<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property string $action
 * @property int|string $actor_member_id
 * @property string|null $body
 * @property string $created_at
 * @property int|string $id
 * @property int|string $thread_id
 * @property int|string $thread_revision
 */
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
