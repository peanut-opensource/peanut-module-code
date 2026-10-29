<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Validation;

use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleCate;
use app\common\validate\PageSizeRule;
use app\common\validate\TenantContextValidate;

class ArticleValidate extends TenantContextValidate
{
    use PageSizeRule;

    protected $rule = [
        'id'         => 'require|checkArticle',
        'title'      => 'require|length:1,255',
        'cid'        => 'require|integer|gt:0|checkCategory',
        'is_show'    => 'require|in:0,1',
        'desc'       => 'max:255',
        'abstract'   => 'max:10000',
        'image'      => 'max:2048',
        'author'     => 'max:255',
        'content'    => 'max:1000000',
        'click_virtual' => 'integer|egt:0',
        'sort'       => 'integer|egt:0',
        'ids'        => 'array|checkIds',
        'page_no'    => 'integer|gt:0',
        'page_size'  => 'integer|gt:0|pageSizeMax',
        'page_start' => 'integer|gt:0',
        'page_end'   => 'integer|gt:0|egt:page_start',
        'page_type'  => 'in:0,1',
        'order_by'   => 'in:desc,asc',
        'start_time' => 'date',
        'end_time'   => 'date|gt:start_time',
        'start'      => 'number',
        'end'        => 'number',
        'export'     => 'in:1,2',
        'file_name'  => 'max:120',
        'field'      => 'in:create_time,id',
    ];

    protected $message = [
        'id.require'    => '资讯id不能为空',
        'title.require' => '标题不能为空',
        'title.length'  => '标题长度须在1-255位字符',
        'cid.require'   => '所属栏目必须存在',
        'page_end.egt'  => '导出范围设置不正确，请重新选择',
        'end_time.gt'   => '搜索的时间范围不正确',
    ];

    protected $scene = [
        'lists'  => [
            'page_no', 'page_size', 'page_start', 'page_end', 'page_type',
            'order_by', 'field', 'title', 'cid', 'is_show',
            'start_time', 'end_time', 'start', 'end', 'export', 'file_name',
        ],
        'add'    => [
            'title', 'cid', 'desc', 'abstract', 'image', 'author', 'content',
            'click_virtual', 'is_show', 'sort',
        ],
        'edit'   => [
            'id', 'title', 'cid', 'desc', 'abstract', 'image', 'author', 'content',
            'click_virtual', 'is_show', 'sort',
        ],
        'delete' => ['id'],
        'detail' => ['id'],
        'status' => ['id', 'is_show'],
    ];

    public function sceneLists(): self
    {
        return $this->only($this->scene['lists'])
            ->remove('title', 'require')
            ->remove('cid', 'require')
            ->remove('is_show', 'require');
    }

    public function sceneDelete(): self
    {
        return $this->only(['id'])->replace('id', 'require|integer|gt:0');
    }

    public function sceneRecycle(): self
    {
        // remove 对同一字段是替换而非追加；保留列表已移除的 require，分类筛选仍可省略。
        // 已删除分类可用于回收查询，但传入值仍须是正整数。
        return $this->sceneLists()->remove('cid', 'require|checkCategory');
    }

    public function sceneRecycleDetail(): self
    {
        return $this->only(['id'])->replace('id', 'require|integer|gt:0');
    }

    public function sceneRestore(): self
    {
        return $this->batchIdsScene();
    }

    public function sceneForceDelete(): self
    {
        return $this->batchIdsScene();
    }

    protected function checkArticle($value): bool|string
    {
        $this->requireTenantContext();
        return Article::where([])->where('id', (int) $value)->findOrEmpty()->isEmpty()
            ? '资讯不存在' : true;
    }

    protected function checkCategory($value): bool|string
    {
        $this->requireTenantContext();
        return ArticleCate::where([])->where('id', (int) $value)->findOrEmpty()->isEmpty()
            ? '所属栏目必须存在' : true;
    }

    protected function checkIds(mixed $value): bool|string
    {
        if (!is_array($value) || $value === [] || count($value) > 100) {
            return '操作对象数量须在1到100之间';
        }
        foreach ($value as $id) {
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id <= 0) {
                return '操作对象 ID 无效';
            }
        }
        return true;
    }

    private function batchIdsScene(): self
    {
        return $this->only(['id', 'ids'])
            ->replace('id', 'requireWithout:ids|integer|gt:0')
            ->replace('ids', 'requireWithout:id|array|checkIds');
    }

}
