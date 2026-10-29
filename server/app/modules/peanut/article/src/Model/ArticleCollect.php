<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

class ArticleCollect extends TenantOwnedModel
{
    use SoftDelete;

    protected $name       = 'article_collect';
    protected $deleteTime = 'delete_time';

    /** 判断某用户是否收藏了某篇文章 */
    public static function isCollected(int $memberId, int $articleId): bool
    {
        if (!$memberId) {
            return false;
        }
        return self::where('member_id', $memberId)
            ->where('article_id', $articleId)
            ->where('status', 1)
            ->count() > 0;
    }
}
