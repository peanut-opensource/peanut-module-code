<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Model;

use app\common\model\TenantOwnedModel;
use app\common\services\HtmlSanitizerService;
use think\facade\Db;
use think\model\concern\SoftDelete;

class Article extends TenantOwnedModel
{
    use SoftDelete;
    protected $name       = 'article';
    protected $deleteTime = 'delete_time';

    /** 关联分类 */
    public function cate()
    {
        return $this->belongsTo(ArticleCate::class, 'cid', 'id');
    }

    /** local 封面存相对 URI；云/CDN 封面保留绝对来源。 */
    public function setImageAttr($value): string
    {
        return trim((string) $value);
    }

    /** Rich text is sanitized before any content reaches persistence. */
    public function setContentAttr($value): string
    {
        return HtmlSanitizerService::sanitize((string) $value);
    }

    /** 可见文章详情；读取即累计一次真实浏览量。 */
    public static function getArticleDetailArr(int $id): array
    {
        $updated = self::where([])
            ->where(['id' => $id, 'is_show' => 1])
            ->setInc('click_actual');
        if ($updated !== 1) {
            return [];
        }

        $article = self::where('id', $id)->findOrEmpty();
        $data = $article->toArray();
        $data['click'] = (int) $data['click_actual'] + (int) $data['click_virtual'];
        unset($data['click_actual'], $data['click_virtual']);
        return $data;
    }

    /** @return list<array<string,mixed>> */
    public static function topPublishedByCategories(array $categoryIds, int $limit): array
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($categoryIds === [] || $limit < 1) {
            return [];
        }
        $ranked = self::where([])->alias('a')->field([
            'a.id', 'a.cid', 'a.title', 'a.desc', 'a.abstract', 'a.image', 'a.author',
            'a.is_show', 'a.sort', 'a.create_time', 'a.update_time', 'a.delete_time',
            'a.click_virtual', 'a.click_actual',
        ])->fieldRaw(
            'ROW_NUMBER() OVER (PARTITION BY a.cid ORDER BY a.sort DESC, a.id DESC) AS category_rank',
        )->whereIn('a.cid', $categoryIds)->where('a.is_show', 1)->buildSql();

        return Db::table($ranked . ' ranked')
            ->where('category_rank', '<=', $limit)
            ->order(['cid' => 'asc', 'sort' => 'desc', 'id' => 'desc'])
            ->select()->toArray();
    }
}
