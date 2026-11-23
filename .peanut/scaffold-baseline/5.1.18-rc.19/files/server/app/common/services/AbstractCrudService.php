<?php

declare(strict_types=1);

namespace app\common\services;

use app\common\contract\audit\AuditResource;
use app\common\services\audit\AuditContractHost;
use app\common\exception\BusinessException;
use app\common\http\PageResult;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Query\DeclarativeQueryBuilder;
use PeanutAdmin\Kernel\Support\DataMasker;
use PeanutAdmin\Kernel\Support\FieldDiffCalculator;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use think\db\BaseQuery;
use think\facade\Db;
use think\Model;

/** The application supplies its authorised, tenant-scoped query and new model. */
abstract class AbstractCrudService
{
    /** @return BaseQuery<Model> */
    abstract protected function query(TenantContext $context): BaseQuery;
    abstract protected function newModel(TenantContext $context): Model;
    abstract protected function assertPermission(TenantContext $context, string $action): void;
    abstract protected function listFields(): array;
    abstract protected function detailFields(): array;
    abstract protected function insertFields(): array;
    abstract protected function updateFields(): array;

    protected function primaryKey(): string { return 'id'; }
    protected function filterFields(): array { return []; }
    protected function memberProjectionFields(): array { return []; }
    protected function publicFileProjectionFields(): array { return []; }
    protected function maskedFields(): array { return []; }
    protected function revealMaskedFields(TenantContext $context): bool { return false; }
    protected function richTextFields(): array { return []; }
    protected function diffFields(): array { return []; }
    protected function auditResourceType(): string { return static::class; }
    protected function onFieldDiff(TenantContext $context, Model $model, array $diff): void {}
    protected function statusField(): ?string { return null; }
    protected function versionField(): ?string { return null; }
    protected function configureListQuery(TenantContext $context, BaseQuery $query, array $params): void {}
    protected function configureDetailQuery(TenantContext $context, BaseQuery $query): void {}
    protected function configureListOrder(BaseQuery $query): void { $query->order($this->primaryKey(), 'desc'); }
    protected function beforeAdd(TenantContext $context, array $params): void {}
    protected function beforeEdit(TenantContext $context, Model $model, array $params): void {}
    protected function beforeDelete(TenantContext $context, Model $model): void {}
    protected function resourceError(string $operation): BusinessException
    {
        return match ($operation) {
            'notFound' => BusinessException::notFound('CRUD_NOT_FOUND', '数据不存在'),
            'delete' => BusinessException::invalid('CRUD_DELETE_FAILED', '删除失败'),
            'statusUnsupported' => BusinessException::invalid('CRUD_STATUS_UNSUPPORTED', '该资源未声明状态字段'),
            default => BusinessException::invalid('CRUD_SAVE_FAILED', '保存失败'),
        };
    }
    protected function decorateRows(TenantContext $context, array $rows): array
    {
        $helper = new BatchProjectionHelper();
        if ($this->memberProjectionFields() !== []) {
            $rows = $helper->members($context, $rows, $this->memberProjectionFields(), app(AdminDirectoryQuery::class)->memberDisplayNames(...));
        }
        if ($this->publicFileProjectionFields() !== []) {
            $rows = $helper->publicFiles($rows, $this->publicFileProjectionFields(), app(FileReferences::class)->getFileUrls(...));
        }
        foreach ($rows as &$row) $row = $this->maskRow($context, $row);
        return $rows;
    }

    protected function maskRow(TenantContext $context, array $row): array
    {
        $masker = new DataMasker();
        foreach ($this->maskedFields() as $field => $type) {
            if (!isset($row[$field])) continue;
            if (!in_array($type, ['phone', 'email', 'identity', 'name'], true)) throw new \LogicException('CRUD_MASK_TYPE_INVALID');
            $row[$field] = $masker->{$type}((string) $row[$field], $this->revealMaskedFields($context));
        }
        return $row;
    }

    private function sanitizeInput(array $values): array
    {
        foreach ($this->richTextFields() as $field) {
            if (isset($values[$field]) && is_string($values[$field])) $values[$field] = HtmlSanitizerService::sanitize($values[$field]);
        }
        return $values;
    }

    public function lists(TenantContext $context, array $params): PageResult|array
    {
        $this->assertPermission($context, 'list');
        $query = $this->query($context)->field($this->listFields());
        (new DeclarativeQueryBuilder($this->filterFields()))->apply($query, $params['filters'] ?? [], null, $params['sort'] ?? []);
        $this->configureListQuery($context, $query, $params);
        if (empty($params['sort'])) $this->configureListOrder($query);
        $page = PaginationInput::from($params)->result($query);
        $rows = array_map(fn($row): array => $this->project($row, $this->listFields()), $page->items);
        return $page->withItems($this->decorateRows($context, $rows));
    }

    public function detail(TenantContext $context, int|string $id): array
    {
        $this->assertPermission($context, 'detail');
        $model = $this->find($context, $id, detail: true);
        $detail = $this->maskRow($context, $this->project($model, $this->detailFields()));
        $auditFields = $this->auditFields($this->diffFields());
        if ($auditFields !== []) {
            $detail['audit_history'] = app(AuditContractHost::class)->crudFieldHistory(
                $context, $this->auditResource($model), $auditFields,
            );
        }
        return $detail;
    }

    public function add(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, 'add');
        return Db::transaction(function () use ($context, $params): bool {
            $this->beforeAdd($context, $params);
            $model = $this->newModel($context);
            if ($model->save($this->sanitizeInput(array_intersect_key($params, array_flip($this->insertFields())))) === false) {
                throw $this->resourceError('save');
            }
            return true;
        });
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, 'edit');
        return Db::transaction(function () use ($context, $params): bool {
            $model = $this->find($context, $params[$this->primaryKey()], true);
            $this->beforeEdit($context, $model, $params);
            $values = $this->sanitizeInput(array_intersect_key($params, array_flip($this->updateFields())));
            $versionField = $this->versionField();
            if ($versionField !== null) {
                $expected = $params[$versionField] ?? null;
                if ((!is_int($expected) && !is_string($expected)) || filter_var($expected, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                    throw new \InvalidArgumentException('版本号无效');
                }
                if ((int) $model->getAttr($versionField) !== (int) $expected) {
                    throw BusinessException::conflict('CRUD_VERSION_CONFLICT', '数据已被修改，请刷新后重试');
                }
                $values[$versionField] = (int) $expected + 1;
            }
            $this->saveUpdate($context, $model, $values, notify: true);
            return true;
        });
    }

    public function delete(TenantContext $context, int|string $id): bool
    {
        $this->assertPermission($context, 'delete');
        return Db::transaction(function () use ($context, $id): bool {
            $model = $this->find($context, $id, true);
            $this->beforeDelete($context, $model);
            if ($model->delete() === false) {
                throw $this->resourceError('delete');
            }
            return true;
        });
    }

    public function updateStatus(TenantContext $context, int|string $id, int $status): bool
    {
        $this->assertPermission($context, 'status');
        $field = $this->statusField();
        if ($field === null) {
            throw $this->resourceError('statusUnsupported');
        }
        return Db::transaction(function () use ($context, $id, $status, $field): bool {
            $model = $this->find($context, $id, true);
            $this->saveUpdate($context, $model, $this->statusValues($model, $field, $status));
            return true;
        });
    }

    /** @param array{ids:list<int|string>} $params */
    public function batchDelete(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, 'batchDelete');
        $ids = $this->batchIds($params);
        return Db::transaction(function () use ($context, $ids): bool {
            foreach ($this->findBatch($context, $ids) as $model) {
                $this->beforeDelete($context, $model);
                if ($model->delete() === false) throw $this->resourceError('delete');
            }
            return true;
        });
    }

    /** @param array{ids:list<int|string>,status?:int|string} $params */
    public function batchUpdateStatus(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, 'batchUpdateStatus');
        $ids = $this->batchIds($params);
        $field = $this->statusField();
        if ($field === null) throw $this->resourceError('statusUnsupported');
        $status = $params['status'] ?? null;
        if ((!is_int($status) && !is_string($status)) || filter_var($status, FILTER_VALIDATE_INT) === false) throw new \InvalidArgumentException('状态值错误');
        return Db::transaction(function () use ($context, $ids, $field, $status): bool {
            foreach ($this->findBatch($context, $ids) as $model) {
                $this->saveUpdate($context, $model, $this->statusValues($model, $field, (int) $status));
            }
            return true;
        });
    }

    private function saveUpdate(TenantContext $context, Model $model, array $values, bool $notify = false): void
    {
        $diffFields = $this->diffFields();
        $before = $diffFields === [] ? [] : $this->project($model, $diffFields);
        if ($model->save($values) === false) {
            throw $this->resourceError('save');
        }
        if ($diffFields === []) {
            return;
        }
        $diff = (new FieldDiffCalculator())->calculate($before, $this->project($model, $diffFields));
        if ($notify) {
            $this->onFieldDiff($context, $model, $diff);
        }
        $auditDiff = array_intersect_key($diff, $this->auditFields($diffFields));
        if ($auditDiff !== []) {
            app(AuditContractHost::class)->recordCrudFieldDiff($context, $this->auditResource($model), $auditDiff);
        }
    }

    /** History is limited to opted-in, unmasked fields visible in resource detail. */
    private function auditFields(array $diffFields): array
    {
        if ($diffFields === []) {
            return [];
        }
        return array_diff_key(
            array_intersect_key(array_flip($diffFields), array_flip($this->detailFields())),
            $this->maskedFields(),
        );
    }

    private function auditResource(Model $model): AuditResource
    {
        return new AuditResource($this->auditResourceType(), (string) $model->getAttr($this->primaryKey()));
    }

    private function statusValues(Model $model, string $field, int $status): array
    {
        $values = [$field => $status];
        $version = $this->versionField();
        if ($version !== null) $values[$version] = (int) $model->getAttr($version) + 1;
        return $values;
    }

    private function findBatch(TenantContext $context, array $ids): iterable
    {
        $models = $this->query($context)->whereIn($this->primaryKey(), $ids)
            ->order($this->primaryKey(), 'asc')->lock(true)->select();
        if (count($models) !== count($ids)) throw $this->resourceError('notFound');
        return $models;
    }

    private function batchIds(array $params): array
    {
        $ids = $params['ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === []) throw new \InvalidArgumentException('操作对象格式错误');
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || (string) $id === '') throw new \InvalidArgumentException('操作对象格式错误');
        }
        return array_values(array_unique($ids, SORT_REGULAR));
    }

    protected function find(TenantContext $context, int|string $id, bool $lock = false, bool $detail = false): Model
    {
        $query = $this->query($context)->where($this->primaryKey(), $id);
        if ($detail) $this->configureDetailQuery($context, $query);
        if ($lock) $query->lock(true);
        $model = $query->findOrEmpty();
        if ($model->isEmpty()) throw $this->resourceError('notFound');
        return $model;
    }

    protected function project(Model|array $record, array $fields): array
    {
        return array_intersect_key($record instanceof Model ? $record->toArray() : $record, array_flip($fields));
    }
}
