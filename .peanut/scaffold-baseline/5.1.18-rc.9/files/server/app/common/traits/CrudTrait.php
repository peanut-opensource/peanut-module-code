<?php

declare(strict_types=1);

namespace app\common\traits;

use app\common\exception\BusinessException;
use app\common\http\PageResult;
use app\common\validate\CrudValidationException;
use LogicException;
use think\exception\ValidateException;
use think\response\Json;

/**
 * Standard, Ergonomic CRUD Trait for ThinkPHP 8 Controllers.
 * Decoupled from specific Context (Tenant / Platform / Global) using composition.
 */
trait CrudTrait
{
    use ApiResponseTrait;

    final public function lists(): Json
    {
        $context = $this->resolveCrudContext();
        $params = $this->readCrudInput(fn() => $this->listsInput($context));
        return $this->renderLists($this->assembleListResult($this->performLists($context, $params), $context));
    }

    final public function detail(): Json
    {
        $context = $this->resolveCrudContext();
        $params = $this->readCrudInput(fn() => $this->detailInput($context));
        return $this->renderDetail($this->assembleDetailResult($this->performDetail($context, $params), $context));
    }

    final public function add(): Json
    {
        $context = $this->resolveCrudContext();
        return $this->renderMutation(
            $this->performAdd($context, $this->readCrudInput(fn() => $this->addInput($context))),
            $this->crudAddSuccessMessage(),
        );
    }

    final public function edit(): Json
    {
        $context = $this->resolveCrudContext();
        return $this->renderMutation(
            $this->performEdit($context, $this->readCrudInput(fn() => $this->editInput($context))),
            $this->crudEditSuccessMessage(),
        );
    }

    final public function delete(): Json
    {
        $context = $this->resolveCrudContext();
        return $this->renderMutation(
            $this->performDelete($context, $this->readCrudInput(fn() => $this->deleteInput($context))),
            $this->crudDeleteSuccessMessage(),
        );
    }

    final public function updateStatus(): Json
    {
        $context = $this->resolveCrudContext();
        return $this->renderMutation(
            $this->performStatusUpdate($context, $this->readCrudInput(fn() => $this->statusInput($context))),
            $this->crudStatusSuccessMessage(),
        );
    }

    /** 仅在 Controller 显式开启并登记路由时提供回收站列表。 */
    final public function recycleLists(): Json
    {
        $this->assertCrudSoftDeleteEnabled();
        $context = $this->resolveCrudContext();
        $params = $this->readCrudInput(fn() => $this->validatedInput($context, 'recycle', $this->request->get()));
        return $this->renderLists($this->assembleListResult($this->performRecycleLists($context, $params), $context));
    }

    final public function recycleDetail(): Json
    {
        $this->assertCrudSoftDeleteEnabled();
        $context = $this->resolveCrudContext();
        $params = $this->readCrudInput(fn() => $this->validatedInput($context, 'recycleDetail', $this->request->get()));
        return $this->renderDetail($this->assembleDetailResult($this->performRecycleDetail($context, $params), $context));
    }

    final public function restore(): Json
    {
        $this->assertCrudSoftDeleteEnabled();
        $context = $this->resolveCrudContext();
        return $this->renderSoftDeleteMutation($this->performRestore(
            $context,
            $this->readCrudInput(fn() => $this->validatedInput($context, 'restore', $this->request->post())),
        ), '恢复成功');
    }

    final public function forceDelete(): Json
    {
        $this->assertCrudSoftDeleteEnabled();
        $context = $this->resolveCrudContext();
        return $this->renderSoftDeleteMutation($this->performForceDelete(
            $context,
            $this->readCrudInput(fn() => $this->validatedInput($context, 'forceDelete', $this->request->post())),
        ), '永久删除成功');
    }

    /** 新生成代码使用 purge 作为稳定动作名；既有 forceDelete 合同保持不变。 */
    final public function purge(): Json
    {
        $this->assertCrudSoftDeleteEnabled();
        $context = $this->resolveCrudContext();
        return $this->renderSoftDeleteMutation($this->performPurge(
            $context,
            $this->readCrudInput(fn() => $this->validatedInput($context, 'purge', $this->request->post())),
        ), '永久删除成功');
    }

    /** Additional operations require an explicit action policy and route. */
    final public function export(): Json { return $this->crudExtraAction('export', true); }
    final public function importTemplate(): Json { return $this->crudExtraAction('importTemplate', true); }
    final public function importPreview(): Json { return $this->crudExtraAction('importPreview'); }
    final public function import(): Json { return $this->crudExtraAction('import'); }
    final public function batchDelete(): Json { return $this->crudExtraAction('batchDelete'); }
    final public function batchUpdateStatus(): Json { return $this->crudExtraAction('batchUpdateStatus'); }

    protected function crudExtraInput(mixed $context, string $action, bool $read): array
    {
        return $this->validatedInput($context, $action, $read ? $this->request->get() : $this->request->post());
    }

    protected function performCrudExtraAction(mixed $context, string $action, array $params): bool|array
    {
        return $this->crudService()->{$action}($context, $params);
    }

    private function crudExtraAction(string $action, bool $read = false): Json
    {
        $actions = defined(static::class . '::CRUD_ACTIONS') ? static::CRUD_ACTIONS : [];
        if (!is_array($actions)) throw new LogicException(static::class . ' has invalid CRUD_ACTIONS configuration.');
        if (($actions[$action] ?? false) !== true) {
            throw BusinessException::notFound('CRUD_ACTION_NOT_FOUND', '操作不存在');
        }
        $context = $this->resolveCrudContext();
        $params = $this->readCrudInput(fn() => $this->crudExtraInput($context, $action, $read));
        $result = $this->performCrudExtraAction($context, $action, $params);
        return is_array($result) ? $this->data($result) : $this->renderMutation($result, '操作成功');
    }

    abstract protected function resolveCrudContext(): mixed;

    /**
     * 新 Controller 可声明 protected string $crudClass 并直接使用 $this->crud；
     * 既有消费者仍可覆盖本方法，迁移不改变其公开行为。
     */
    protected function crudService(): object
    {
        $service = $this->crud;
        if (!is_object($service)) {
            throw new LogicException(static::class . '::$crud did not resolve to an object.');
        }
        return $service;
    }

    protected function listsInput(mixed $context): array
    {
        $params = $this->request->get();
        return $this->crudValidateLists()
            ? $this->validatedInput($context, 'lists', $params)
            : $params;
    }

    protected function detailInput(mixed $context): array
    {
        return $this->validatedInput($context, 'detail', $this->request->get());
    }

    protected function addInput(mixed $context): array
    {
        $params = $this->validatedInput($context, 'add', $this->request->post());
        // Hook input is already reduced to writable fields. Hooks may add trusted
        // server-side values, but cannot recover rejected request fields.
        $this->beforeAdd($params, $context);
        return $params;
    }

    protected function editInput(mixed $context): array
    {
        $params = $this->validatedInput($context, 'edit', $this->request->post());
        // The primary key is an explicit control field; storage fields remain the
        // CRUD_WRITABLE_FIELDS projection before this trusted hook runs.
        $this->beforeEdit($params, $context);
        return $params;
    }

    protected function deleteInput(mixed $context): array
    {
        $params = $this->validatedInput($context, 'delete', $this->request->post());
        $this->beforeDelete($params, $context);
        return $params;
    }

    protected function statusInput(mixed $context): array
    {
        return $this->validatedInput($context, $this->crudStatusScene(), $this->request->post());
    }

    protected function beforeAdd(array &$params, mixed $context): void {}
    protected function beforeEdit(array &$params, mixed $context): void {}
    protected function beforeDelete(array &$params, mixed $context): void {}

    protected function performLists(mixed $context, array $params): PageResult|array
    {
        return $this->crudService()->lists($context, $params);
    }

    protected function performDetail(mixed $context, array $params): array
    {
        return $this->crudService()->detail(
            $context,
            $this->crudPrimaryKeyValue($params[$this->crudPrimaryKey()] ?? null),
        );
    }

    protected function performAdd(mixed $context, array $params): bool
    {
        return $this->crudService()->add($context, $params);
    }

    protected function performEdit(mixed $context, array $params): bool
    {
        return $this->crudService()->edit($context, $params);
    }

    protected function performDelete(mixed $context, array $params): bool
    {
        return $this->crudService()->delete(
            $context,
            $this->crudPrimaryKeyValue($params[$this->crudPrimaryKey()] ?? null),
        );
    }

    protected function performStatusUpdate(mixed $context, array $params): bool
    {
        $status = $params[$this->crudStatusField()] ?? null;
        if ((!is_int($status) && !is_string($status)) || filter_var($status, FILTER_VALIDATE_INT) === false) {
            throw new CrudValidationException('状态值错误');
        }
        return $this->crudService()->updateStatus(
            $context,
            $this->crudPrimaryKeyValue($params[$this->crudPrimaryKey()] ?? null),
            (int) $status,
        );
    }

    protected function performRecycleLists(mixed $context, array $params): PageResult|array
    {
        return $this->crudService()->recycleLists($context, $params);
    }

    protected function performRecycleDetail(mixed $context, array $params): array
    {
        return $this->crudService()->recycleDetail(
            $context,
            $this->crudPrimaryKeyValue($params[$this->crudPrimaryKey()] ?? null),
        );
    }

    protected function performRestore(mixed $context, array $params): bool|array
    {
        return $this->crudService()->restore($context, $this->crudTargetIds($params));
    }

    protected function performForceDelete(mixed $context, array $params): bool|array
    {
        return $this->crudService()->forceDelete($context, $this->crudTargetIds($params));
    }

    protected function performPurge(mixed $context, array $params): bool|array
    {
        return $this->crudService()->purge($context, $this->crudTargetIds($params));
    }

    /**
     * Receives all authorized page rows together for optional batched response assembly.
     *
     * @param list<mixed> $items
     * @return list<mixed>
     */
    protected function assembleListItems(mixed $context, array $items): array
    {
        return $items;
    }

    /** Receives an existing authorized record before rendering. */
    protected function assembleDetail(mixed $context, array $record): array
    {
        return $record;
    }

    private function assembleListResult(PageResult|array $result, mixed $context): PageResult|array
    {
        if (!$result instanceof PageResult || $result->items === []) {
            return $result;
        }

        return $result->withItems($this->assembleListItems($context, $result->items));
    }

    private function assembleDetailResult(array $result, mixed $context): array
    {
        return $result === [] ? $result : $this->assembleDetail($context, $result);
    }

    protected function renderLists(PageResult|array $result): Json
    {
        return $this->data($result);
    }

    protected function renderDetail(array $result): Json
    {
        if ($result === []) {
            throw BusinessException::notFound('ADMIN_RESOURCE_NOT_FOUND', $this->crudNotFoundMessage());
        }
        return $this->data($result);
    }

    protected function renderMutation(bool $result, string $successMessage): Json
    {
        if (!$result) {
            throw new LogicException('CRUD_MUTATION_MUST_SUCCEED_OR_THROW');
        }
        return $this->success($successMessage);
    }

    protected function renderSoftDeleteMutation(bool|array $result, string $successMessage): Json
    {
        return is_array($result)
            ? $this->data($result)
            : $this->renderMutation($result, $successMessage);
    }

    private function readCrudInput(callable $read): array
    {
        try {
            return $read();
        } catch (ValidateException $exception) {
            throw new CrudValidationException($exception->getError(), $exception->getKey());
        }
    }

    protected function validatedInput(
        mixed $context,
        string $scene,
        array $params,
    ): array {
        $validatorClass = $this->crudValidateClass();
        $accepted = $this->crudInputFields($scene);
        if ($accepted !== null) {
            $writable = $this->crudWritableFields($scene);
            if (in_array($scene, ['add', 'edit', $this->crudStatusScene()], true) && $writable === null) {
                throw new LogicException(sprintf(
                    '%s must declare CRUD_WRITABLE_FIELDS for %s.',
                    static::class,
                    $scene,
                ));
            }
            $input = $this->validateInput(
                $params,
                $validatorClass . '.' . $scene,
                $accepted,
                $writable ?? [],
                rejectUnknown: true,
            );
            if (!$this->crudIsWriteScene($scene)) {
                return $input->validated();
            }

            $payload = $input->writable();
            $validated = $input->validated();
            foreach ($this->crudControlFields($scene) as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }
            return $payload;
        }

        if (defined(static::class . '::CRUD_INPUT_FIELDS')) {
            throw new LogicException(sprintf(
                '%s must declare valid CRUD_INPUT_FIELDS for %s.',
                static::class,
                $scene,
            ));
        }

        // 未迁移消费者保留原字段行为；显式字段政策按 Controller 逐个接入。
        return $this->validate($params, $validatorClass . '.' . $scene)->validated();
    }

    protected function crudValidateClass(): string
    {
        $validate = defined(static::class . '::CRUD_VALIDATE') ? static::CRUD_VALIDATE : '';
        if ($validate === '' || !class_exists($validate)) {
            throw new LogicException(sprintf(
                '%s must configure a valid CRUD_VALIDATE class.',
                static::class,
            ));
        }
        return $validate;
    }

    protected function crudAddSuccessMessage(): string
    {
        return defined(static::class . '::CRUD_ADD_SUCCESS_MESSAGE') ? static::CRUD_ADD_SUCCESS_MESSAGE : '操作成功';
    }

    protected function crudEditSuccessMessage(): string
    {
        return defined(static::class . '::CRUD_EDIT_SUCCESS_MESSAGE') ? static::CRUD_EDIT_SUCCESS_MESSAGE : '操作成功';
    }

    protected function crudDeleteSuccessMessage(): string
    {
        return defined(static::class . '::CRUD_DELETE_SUCCESS_MESSAGE') ? static::CRUD_DELETE_SUCCESS_MESSAGE : '操作成功';
    }

    protected function crudStatusSuccessMessage(): string
    {
        return defined(static::class . '::CRUD_STATUS_SUCCESS_MESSAGE') ? static::CRUD_STATUS_SUCCESS_MESSAGE : '操作成功';
    }

    protected function crudNotFoundMessage(): string
    {
        return defined(static::class . '::CRUD_NOT_FOUND_MESSAGE') ? static::CRUD_NOT_FOUND_MESSAGE : '数据不存在';
    }

    protected function crudStatusField(): string
    {
        return defined(static::class . '::CRUD_STATUS_FIELD') ? static::CRUD_STATUS_FIELD : 'is_disable';
    }

    protected function crudStatusScene(): string
    {
        return defined(static::class . '::CRUD_STATUS_SCENE') ? static::CRUD_STATUS_SCENE : 'status';
    }

    protected function crudPrimaryKey(): string
    {
        $key = defined(static::class . '::CRUD_PRIMARY_KEY') ? (string) static::CRUD_PRIMARY_KEY : 'id';
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
            throw new LogicException(static::class . ' has an invalid CRUD_PRIMARY_KEY declaration.');
        }
        return $key;
    }

    protected function crudPrimaryKeyType(): string
    {
        $type = strtolower(defined(static::class . '::CRUD_PRIMARY_KEY_TYPE')
            ? (string) static::CRUD_PRIMARY_KEY_TYPE
            : 'int');
        return match ($type) {
            'int', 'integer' => 'int',
            'string' => 'string',
            default => throw new LogicException(
                static::class . ' only supports int or string CRUD primary keys.',
            ),
        };
    }

    protected function crudValidateLists(): bool
    {
        return defined(static::class . '::CRUD_VALIDATE_LISTS') ? (bool) static::CRUD_VALIDATE_LISTS : false;
    }

    /** @return array<int|string,mixed>|null */
    protected function crudInputFields(string $scene): ?array
    {
        $policies = defined(static::class . '::CRUD_INPUT_FIELDS') ? static::CRUD_INPUT_FIELDS : null;
        return is_array($policies) && isset($policies[$scene]) && is_array($policies[$scene])
            ? $policies[$scene]
            : null;
    }

    /** @return array<int|string,mixed>|null */
    protected function crudWritableFields(string $scene): ?array
    {
        $policies = defined(static::class . '::CRUD_WRITABLE_FIELDS') ? static::CRUD_WRITABLE_FIELDS : null;
        return is_array($policies) && isset($policies[$scene]) && is_array($policies[$scene])
            ? $policies[$scene]
            : null;
    }

    private function assertCrudSoftDeleteEnabled(): void
    {
        if (!defined(static::class . '::CRUD_SOFT_DELETE') || static::CRUD_SOFT_DELETE !== true) {
            throw new LogicException(static::class . ' has not enabled CRUD soft-delete actions.');
        }
    }

    /** @return list<string> */
    protected function crudControlFields(string $scene): array
    {
        $primary = $this->crudPrimaryKey();
        $defaults = match ($scene) {
            'edit', 'delete', $this->crudStatusScene(), 'recycleDetail' => [$primary],
            'restore', 'forceDelete', 'purge', 'batchDelete', 'batchUpdateStatus' => [$primary, 'ids'],
            default => [],
        };
        $policies = defined(static::class . '::CRUD_CONTROL_FIELDS') ? static::CRUD_CONTROL_FIELDS : null;
        $declared = is_array($policies) && isset($policies[$scene]) && is_array($policies[$scene])
            ? $policies[$scene]
            : [];
        foreach ($declared as $field) {
            if (!is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $field) !== 1) {
                throw new LogicException(static::class . ' has an invalid CRUD_CONTROL_FIELDS declaration.');
            }
        }
        return array_values(array_unique([...$defaults, ...$declared]));
    }

    private function crudIsWriteScene(string $scene): bool
    {
        return $this->crudWritableFields($scene) !== null
            || in_array($scene, [
                'add', 'edit', 'delete', $this->crudStatusScene(), 'restore', 'forceDelete', 'purge',
                'import', 'batchDelete', 'batchUpdateStatus',
            ], true);
    }

    private function crudPrimaryKeyValue(mixed $value): int|string
    {
        if ($this->crudPrimaryKeyType() === 'string') {
            if (!is_string($value) && !is_int($value)) {
                throw new BusinessException('CRUD_PRIMARY_KEY_INVALID', 422, '主键格式错误');
            }
            $key = trim((string) $value);
            if ($key === '') {
                throw new BusinessException('CRUD_PRIMARY_KEY_INVALID', 422, '主键格式错误');
            }
            return $key;
        }

        if ((!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new BusinessException('CRUD_PRIMARY_KEY_INVALID', 422, '主键格式错误');
        }
        return (int) $value;
    }

    /** @return list<int|string> */
    private function crudTargetIds(array $params): array
    {
        if (isset($params['ids']) && (!is_array($params['ids']) || $params['ids'] === [])) {
            throw new CrudValidationException('操作对象格式错误');
        }
        $ids = $params['ids'] ?? [$params[$this->crudPrimaryKey()] ?? null];
        $parsed = array_map(fn(mixed $id): int|string => $this->crudPrimaryKeyValue($id), $ids);
        return array_values(array_unique($parsed, SORT_REGULAR));
    }
}
