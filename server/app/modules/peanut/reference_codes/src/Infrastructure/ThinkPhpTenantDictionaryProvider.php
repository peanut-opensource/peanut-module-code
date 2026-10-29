<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Infrastructure;

use PeanutAdmin\Modules\ReferenceCodes\Model\DictData;
use PeanutAdmin\Modules\ReferenceCodes\Model\DictType;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryCommandProvider;
use PeanutAdmin\Modules\ReferenceCodes\Contract\TenantDictionaryQueryProvider;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryEntry;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryPage;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryType;
use app\common\exception\BusinessException;
use think\facade\Db;
use app\common\support\PaginationInput;

/** ThinkPHP/ThinkORM implementation of the core dictionary ports. */
final class ThinkPhpTenantDictionaryProvider implements TenantDictionaryQueryProvider, TenantDictionaryCommandProvider
{
    /** @param array<string,mixed> $filters */
    public function types(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPage
    {
        $where = [];
        if (!empty($filters['name'])) {
            $where[] = ['name', 'like', '%' . $filters['name'] . '%'];
        }
        if (!empty($filters['type'])) {
            $where[] = ['type', 'like', '%' . $filters['type'] . '%'];
        }
        if (isset($filters['is_disable']) && $filters['is_disable'] !== '') {
            $where[] = ['is_disable', '=', (int) $filters['is_disable']];
        }
        $query = $this->typesQuery()->where($where);
        $pageResult = PaginationInput::from(['page_no' => $page, 'page_size' => $pageSize])->result($query->order(['id' => 'desc']));
        $rows = array_map(static fn($item): array => $item instanceof \think\Model ? $item->toArray() : (array) $item, $pageResult->items);
        return new DictionaryPage(array_map(static fn(array $row): DictionaryType => DictionaryType::fromArray($row), $rows), $pageResult->total, $pageResult->page, $pageResult->pageSize);
    }

    /** @param array<string,mixed> $filters */
    public function entries(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPage
    {
        $where = [];
        if (!empty($filters['type_id'])) {
            $where[] = ['type_id', '=', (int) $filters['type_id']];
        }
        if (!empty($filters['name'])) {
            $where[] = ['name', 'like', '%' . $filters['name'] . '%'];
        }
        if (isset($filters['is_disable']) && $filters['is_disable'] !== '') {
            $where[] = ['is_disable', '=', (int) $filters['is_disable']];
        }
        $query = $this->entriesQuery()->where($where);
        $pageResult = PaginationInput::from(['page_no' => $page, 'page_size' => $pageSize])->result($query->order(['sort' => 'desc', 'id' => 'desc']));
        $rows = array_map(static fn($item): array => $item instanceof \think\Model ? $item->toArray() : (array) $item, $pageResult->items);
        return new DictionaryPage(array_map(static fn(array $row): DictionaryEntry => DictionaryEntry::fromArray($row), $rows), $pageResult->total, $pageResult->page, $pageResult->pageSize);
    }

    public function type(TenantContext $context, int $id): ?DictionaryType
    {
        $model = $this->typesQuery()->where('id', $id)->findOrEmpty();
        return $model->isEmpty() ? null : DictionaryType::fromArray($model->toArray());
    }

    /** @return list<DictionaryType> */
    public function enabledTypes(TenantContext $context): array
    {
        $rows = $this->typesQuery()->where('is_disable', 0)->field('id,name,type')->order(['id' => 'desc'])->select()->toArray();
        return array_map(static fn(array $row): DictionaryType => DictionaryType::fromArray($row), $rows);
    }

    public function entry(TenantContext $context, int $id): ?DictionaryEntry
    {
        $model = $this->entriesQuery()->where('id', $id)->findOrEmpty();
        return $model->isEmpty() ? null : DictionaryEntry::fromArray($model->toArray());
    }

    /** @return list<DictionaryEntry> */
    public function enabledEntriesByType(TenantContext $context, string $type): array
    {
        $rows = $this->entriesQuery()->where('type_value', $type)->where('is_disable', 0)
            ->field('id,name,value,sort')->order(['sort' => 'desc', 'id' => 'desc'])->select()->toArray();
        return array_map(static fn(array $row): DictionaryEntry => DictionaryEntry::fromArray($row + ['source' => 'tenant']), $rows);
    }

    /** @param array<string,mixed> $values */
    public function createType(TenantContext $context, array $values): DictionaryType
    {
        unset($values['tenant_id']);
        if ($this->typeExists((string) ($values['type'] ?? ''))) {
            throw BusinessException::conflict('DICTIONARY_TYPE_EXISTS', '字典类型标识已存在');
        }
        $model = DictType::create($values);
        return DictionaryType::fromArray($model->toArray());
    }

    /** @param array<string,mixed> $values */
    public function replaceType(TenantContext $context, int $id, array $values): DictionaryType
    {
        unset($values['tenant_id']);
        if ($this->typeExists((string) ($values['type'] ?? ''), $id)) {
            throw BusinessException::conflict('DICTIONARY_TYPE_EXISTS', '字典类型标识已存在');
        }
        return Db::transaction(function () use ($id, $values): DictionaryType {
            $model = $this->typesQuery()->where('id', $id)->lock(true)->findOrEmpty();
            if ($model->isEmpty()) {
                throw BusinessException::notFound('DICTIONARY_TYPE_NOT_FOUND', '字典类型不存在');
            }
            $model->name = (string) ($values['name'] ?? '');
            $model->type = (string) ($values['type'] ?? '');
            $model->is_disable = (int) ($values['is_disable'] ?? 0);
            $model->remark = (string) ($values['remark'] ?? '');
            $model->save();
            $this->entriesQuery()->where('type_id', (int) $model->id)->update(['type_value' => (string) $model->type]);
            return DictionaryType::fromArray($model->toArray());
        });
    }

    public function deleteType(TenantContext $context, int $id): void
    {
        Db::transaction(function () use ($id): void {
            $model = $this->typesQuery()->where('id', $id)->lock(true)->findOrEmpty();
            if ($model->isEmpty()) {
                throw BusinessException::notFound('DICTIONARY_TYPE_NOT_FOUND', '字典类型不存在');
            }
            if (!$this->entriesQuery()->where('type_id', $id)->lock(true)->findOrEmpty()->isEmpty()) {
                throw BusinessException::conflict('DICTIONARY_TYPE_IN_USE', '字典类型已被数据项使用，请先删除数据项');
            }
            $model->delete();
        });
    }

    public function setTypeDisabled(TenantContext $context, int $id, bool $disabled): void
    {
        $model = $this->typesQuery()->where('id', $id)->findOrEmpty();
        if ($model->isEmpty()) {
            throw BusinessException::notFound('DICTIONARY_TYPE_NOT_FOUND', '字典类型不存在');
        }
        $model->is_disable = $disabled ? 1 : 0;
        $model->save();
    }

    /** @param array<string,mixed> $values */
    public function createEntry(TenantContext $context, array $values): DictionaryEntry
    {
        unset($values['tenant_id']);
        $type = $this->typesQuery()->where('id', (int) ($values['type_id'] ?? 0))->findOrEmpty();
        if ($type->isEmpty()) {
            throw BusinessException::notFound('DICTIONARY_TYPE_NOT_FOUND', '字典类型不存在');
        }
        $values['type_value'] = (string) $type->type;
        $model = DictData::create($values);
        return DictionaryEntry::fromArray($model->toArray());
    }

    /** @param array<string,mixed> $values */
    public function replaceEntry(TenantContext $context, int $id, array $values): DictionaryEntry
    {
        unset($values['tenant_id'], $values['type_id'], $values['type_value']);
        $model = $this->entriesQuery()->where('id', $id)->findOrEmpty();
        if ($model->isEmpty()) {
            throw BusinessException::notFound('DICTIONARY_ENTRY_NOT_FOUND', '字典数据不存在');
        }
        $model->name = (string) ($values['name'] ?? '');
        $model->value = (string) ($values['value'] ?? '');
        $model->sort = (int) ($values['sort'] ?? 0);
        $model->is_disable = (int) ($values['is_disable'] ?? 0);
        $model->remark = (string) ($values['remark'] ?? '');
        $model->save();
        return DictionaryEntry::fromArray($model->toArray());
    }

    public function deleteEntry(TenantContext $context, int $id): void
    {
        $model = $this->entriesQuery()->where('id', $id)->findOrEmpty();
        if ($model->isEmpty()) {
            throw BusinessException::notFound('DICTIONARY_ENTRY_NOT_FOUND', '字典数据不存在');
        }
        $model->delete();
    }

    public function setEntryDisabled(TenantContext $context, int $id, bool $disabled): void
    {
        $model = $this->entriesQuery()->where('id', $id)->findOrEmpty();
        if ($model->isEmpty()) {
            throw BusinessException::notFound('DICTIONARY_ENTRY_NOT_FOUND', '字典数据不存在');
        }
        $model->is_disable = $disabled ? 1 : 0;
        $model->save();
    }

    private function typesQuery()
    {
        return DictType::where([]);
    }

    private function entriesQuery()
    {
        return DictData::where([]);
    }

    private function typeExists(string $type, int $exceptId = 0): bool
    {
        $query = $this->typesQuery()->where('type', $type);
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        return $query->count() > 0;
    }
}
