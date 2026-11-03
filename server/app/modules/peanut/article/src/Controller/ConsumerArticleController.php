<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Controller;

use app\api\controller\BaseApiController;
use app\common\validate\ListsValidate;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;

/**
 * @property-read PublicArticleQueries $articles
 * @property-read \PeanutAdmin\Modules\Article\Service\ArticleLandingService $landing
 */
class ConsumerArticleController extends BaseApiController
{
    protected string $articlesClass = PublicArticleQueries::class;
    protected string $landingClass = \PeanutAdmin\Modules\Article\Service\ArticleLandingService::class;

    public function mobileIndex()
    {
        return $this->data($this->landing->mobileIndex($this->publicTenantContext('article.index')));
    }

    public function pcIndex()
    {
        return $this->data($this->landing->pcIndex($this->publicTenantContext('article.pc-index')));
    }

    public function infoCenter()
    {
        return $this->data($this->landing->infoCenter($this->publicTenantContext('article.info-center')));
    }

    public function pcDetail()
    {
        return $this->data($this->landing->pcDetail(
            $this->publicTenantContext('article.pc-detail'),
            $this->memberId,
            $this->request->get('id/d', 0),
            $this->request->get('source/s', 'default'),
        ));
    }


    /** 文章列表 */
    public function lists()
    {
        $this->validate($this->request->get(), ListsValidate::class);
        $params = [
            'cid'       => $this->request->get('cid/d', 0),
            'keyword'   => $this->request->get('keyword/s', ''),
            'sort'      => $this->request->get('sort/s', 'default'),
            'page_no'   => $this->request->get('page_no/d', 1),
            'page_size' => $this->request->get('page_size/d', 15),
        ];

        $this->publicTenantContext('article.lists');
        $result = $this->articles->lists($params, $this->memberId);
        return $this->data($result);
    }

    /** 文章分类 */
    public function cate()
    {
        $this->publicTenantContext('article.cate');
        $result = $this->articles->categories();
        return $this->data($result);
    }

    /** 文章详情 */
    public function detail()
    {
        $id     = $this->request->get('id/d', 0);
        $this->publicTenantContext('article.detail');
        $result = $this->articles->detail($id, $this->memberId);

        if ($result === []) {
            throw BusinessException::notFound('ARTICLE_NOT_FOUND', '文章不存在或已下架');
        }

        return $this->data($result);
    }

    /** 加入收藏 */
    public function addCollect()
    {
        $id = $this->request->post('id/d', 0);
        $this->memberContext();
        $this->articles->add($id, $this->memberId);
        return $this->success('操作成功');
    }

    /** 取消收藏 */
    public function cancelCollect()
    {
        $id = $this->request->post('id/d', 0);
        $this->memberContext();
        $this->articles->cancel($id, $this->memberId);
        return $this->success('操作成功');
    }

    /** 我的收藏 */
    public function collect()
    {
        $this->validate($this->request->get(), ListsValidate::class);
        $params = [
            'page_no'   => $this->request->get('page_no/d', 1),
            'page_size' => $this->request->get('page_size/d', 15),
        ];

        $this->memberContext();
        $result = $this->articles->collectionLists($this->memberId, $params);
        return $this->data($result);
    }
}
