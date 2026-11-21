<?php

declare(strict_types=1);

namespace app\common\services;

use PeanutAdmin\Kernel\Auth\TenantContext;
use app\common\exception\BusinessException;
use think\Model;
use think\db\BaseQuery;
use PeanutAdmin\Kernel\Tree\TreeBuilder;

abstract class AbstractTreeCrudService extends AbstractCrudService
{
    protected function parentField(): string { return 'parent_id'; }

    /** Tenant-scoped structure, including nodes hidden by business data permissions. */
    abstract protected function structureQuery(TenantContext $context): BaseQuery;

    protected function beforeAdd(TenantContext $context, array $params): void
    {
        $parent = $params[$this->parentField()] ?? null;
        if ($parent === null || $parent === 0 || $parent === '0') return;
        if (!is_int($parent) && !is_string($parent)) throw new \InvalidArgumentException('上级节点无效');
        $rows = $this->lockedStructure($context);
        $this->assertParentExists($rows, $parent);
    }

    protected function beforeEdit(TenantContext $context, Model $model, array $params): void
    {
        $field = $this->parentField();
        if (!array_key_exists($field, $params)) return;
        $parent = $params[$field];
        if (!is_int($parent) && !is_string($parent) && $parent !== null) throw new \InvalidArgumentException('上级节点无效');
        $rows = $this->lockedStructure($context);
        if ($parent !== null && (string) $parent !== '0') $this->assertParentExists($rows, $parent);
        try {
            (new TreeBuilder())->assertCanMove($rows, $model->getAttr($this->primaryKey()), $parent, $this->primaryKey(), $field);
        } catch (\InvalidArgumentException $exception) {
            throw new BusinessException('CRUD_TREE_PARENT_INVALID', 422, '上级节点无效');
        }
    }

    private function lockedStructure(TenantContext $context): array
    {
        return $this->structureQuery($context)->field([$this->primaryKey(), $this->parentField()])->lock(true)->select()->toArray();
    }

    private function assertParentExists(array $rows, int|string $parent): void
    {
        foreach ($rows as $row) {
            if ((string) $row[$this->primaryKey()] === (string) $parent) return;
        }
        throw new BusinessException('CRUD_TREE_PARENT_INVALID', 422, '上级节点无效');
    }

    public function lists(TenantContext $context, array $params): array
    {
        $this->assertPermission($context, 'list');
        $query = $this->query($context)->field($this->listFields());
        $this->configureListQuery($context, $query, $params);
        $this->configureListOrder($query);
        $rows = $query->limit(2000)->select()->map(fn($row): array => $this->project($row, $this->listFields()))->toArray();
        return $this->buildTree($this->decorateRows($context, $rows));
    }
    protected function buildTree(array $rows): array
    {
        return (new TreeBuilder())->build($rows, $this->primaryKey(), $this->parentField());
    }

    protected function beforeDelete(TenantContext $context, Model $model): void
    {
        if ($this->structureQuery($context)->where($this->parentField(), $model->getAttr($this->primaryKey()))->count() > 0) {
            throw $this->treeHasChildrenError();
        }
    }

    protected function treeHasChildrenError(): BusinessException
    {
        return BusinessException::conflict('CRUD_HAS_CHILDREN', '请先删除下级节点');
    }
}
