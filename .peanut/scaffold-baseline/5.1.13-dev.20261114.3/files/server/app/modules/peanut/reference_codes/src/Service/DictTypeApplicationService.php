<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Service;

use app\common\http\PageResult;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictionaryRuntime;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;

class DictTypeApplicationService
{
    public function __construct(private readonly DictionaryRuntime $dictionaryRuntime) {}

    /** 分页列表：支持 name(模糊) / type(模糊) / is_disable 过滤 */
    public function lists(TenantContext $context, array $params): PageResult
    {
        $filters = [];
        if (!empty($params['name'])) {
            $filters['name'] = (string) $params['name'];
        }
        if (!empty($params['type'])) {
            $filters['type'] = (string) $params['type'];
        }
        if (isset($params['is_disable']) && $params['is_disable'] !== '') {
            $filters['is_disable'] = (int) $params['is_disable'];
        }

        $pagination = PaginationInput::from($params);

        $result = $this->dictionaryRuntime->types(
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

    /** 全部启用类型（供选择器用） */
    public function all(TenantContext $context): array
    {
        return array_map(static function ($type): array {
            return ['id' => $type->id, 'name' => $type->name, 'type' => $type->type];
        }, $this->dictionaryRuntime->enabledTypes($context));
    }

    public function detail(TenantContext $context, int $id): array
    {
        $type = $this->dictionaryRuntime->type($context, $id);
        return $type === null ? [] : $type->toArray();
    }

    public function add(TenantContext $context, array $params): bool
    {
        $this->dictionaryRuntime->createType($context, [
            'name'       => (string) $params['name'],
            'type'       => (string) $params['type'],
            'is_disable' => (int) ($params['is_disable'] ?? 0),
            'remark'     => (string) ($params['remark'] ?? ''),
        ]);
        return true;
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $this->dictionaryRuntime->replaceType($context, (int) $params['id'], [
            'name' => (string) $params['name'],
            'type' => (string) $params['type'],
            'is_disable' => (int) ($params['is_disable'] ?? 0),
            'remark' => (string) ($params['remark'] ?? ''),
        ]);
        return true;
    }

    /** 被数据占用时拒绝删除，避免无意级联丢失业务枚举。 */
    public function delete(TenantContext $context, int $id): bool
    {
        $this->dictionaryRuntime->deleteType($context, $id);
        return true;
    }

    public function updateStatus(TenantContext $context, int $id, int $isDisable): bool
    {
        $this->dictionaryRuntime->setTypeDisabled($context, $id, $isDisable !== 0);
        return true;
    }
}
