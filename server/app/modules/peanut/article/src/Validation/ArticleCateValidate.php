<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Validation;

use PeanutAdmin\Modules\Article\Model\ArticleCate;
use app\common\validate\PageSizeRule;
use app\common\validate\TenantContextValidate;

class ArticleCateValidate extends TenantContextValidate
{
    use PageSizeRule;

    protected $rule = [
        'id'         => 'require|checkArticleCate',
        'name'       => 'require|length:1,90',
        'is_show'    => 'require|in:0,1',
        'sort'       => 'egt:0',
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
        'id.require'     => '资讯分类id不能为空',
        'name.require'   => '资讯分类不能为空',
        'name.length'    => '资讯分类长度须在1-90位字符',
        'sort.egt'       => '排序值不正确',
        'page_end.egt'   => '导出范围设置不正确，请重新选择',
        'end_time.gt'    => '搜索的时间范围不正确',
    ];

    protected $scene = [
        'lists'  => [
            'page_no', 'page_size', 'page_start', 'page_end', 'page_type',
            'order_by', 'field', 'name', 'is_show',
            'start_time', 'end_time', 'start', 'end', 'export', 'file_name',
        ],
        'add'    => ['name', 'is_show', 'sort'],
        'edit'   => ['id', 'name', 'is_show', 'sort'],
        'delete' => ['id'],
        'detail' => ['id'],
        'status' => ['id', 'is_show'],
    ];

    public function sceneEdit(): self
    {
        return $this->only($this->scene['edit'])->replace('id', 'require|integer|gt:0|regex:/^[0-9]+$/');
    }

    public function sceneDelete(): self
    {
        return $this->only(['id'])->replace('id', 'require|integer|gt:0');
    }

    public function sceneLists(): self
    {
        return $this->only($this->scene['lists'])
            ->remove('name', 'require')
            ->remove('is_show', 'require');
    }

    public function sceneRecycle(): self
    {
        return $this->sceneLists();
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

    protected function checkArticleCate($value): bool|string
    {
        $this->requireTenantContext();
        return ArticleCate::where([])->where('id', (int) $value)->findOrEmpty()->isEmpty()
            ? '资讯分类不存在' : true;
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
