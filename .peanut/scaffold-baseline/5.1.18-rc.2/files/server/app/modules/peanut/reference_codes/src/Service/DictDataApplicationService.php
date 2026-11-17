<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Service;

use app\common\http\PageResult;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictionaryRuntime;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;

class DictDataApplicationService
{
    public function __construct(private readonly DictionaryRuntime $dictionaryRuntime) {}

    /** 分页列表：按 type_id 过滤，支持 name(模糊) / is_disable */
    public function lists(TenantContext $context, array $params): PageResult
    {
        $filters = [];
        if (!empty($params['type_id'])) {
            $filters['type_id'] = (int) $params['type_id'];
        }
        if (!empty($params['name'])) {
            $filters['name'] = (string) $params['name'];
        }
        if (isset($params['is_disable']) && $params['is_disable'] !== '') {
            $filters['is_disable'] = (int) $params['is_disable'];
        }

        $pagination = PaginationInput::from($params);

        $result = $this->dictionaryRuntime->entries(
            $context,
            $filters,
            $pagination->page,
            $pagination->pageSize,
        );
        return new PageResult(
            array_map(static fn($item): array => $item->toArray(), $result->items),
            $result->count,
            $result->page,
            $result->pageSize,
        );
    }

    /** 按类型标识取全部启用数据项（业务前端常用：下拉/枚举） */
    public function byType(TenantContext $context, string $typeValue): array
    {
        return array_map(
            static fn($entry): array => $entry->toArray(),
            $this->dictionaryRuntime->enabledByType($context, $typeValue),
        );
    }

    public function detail(TenantContext $context, int $id): array
    {
        $data = $this->dictionaryRuntime->entry($context, $id);
        return $data === null ? [] : $data->toArray();
    }

    public function add(TenantContext $context, array $params): bool
    {
        $this->dictionaryRuntime->createEntry($context, [
            'name'       => (string) $params['name'],
            'value'      => (string) $params['value'],
            'type_id'    => (int) $params['type_id'],
            'sort'       => (int) ($params['sort'] ?? 0),
            'is_disable' => (int) ($params['is_disable'] ?? 0),
            'remark'     => (string) ($params['remark'] ?? ''),
        ]);
        return true;
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $this->dictionaryRuntime->replaceEntry($context, (int) $params['id'], [
            'name' => (string) $params['name'],
            'value' => (string) $params['value'],
            'sort' => (int) ($params['sort'] ?? 0),
            'is_disable' => (int) ($params['is_disable'] ?? 0),
            'remark' => (string) ($params['remark'] ?? ''),
        ]);
        return true;
    }

    public function delete(TenantContext $context, int $id): bool
    {
        $this->dictionaryRuntime->deleteEntry($context, $id);
        return true;
    }

    public function updateStatus(TenantContext $context, int $id, int $isDisable): bool
    {
        $this->dictionaryRuntime->setEntryDisabled($context, $id, $isDisable !== 0);
        return true;
    }
}
