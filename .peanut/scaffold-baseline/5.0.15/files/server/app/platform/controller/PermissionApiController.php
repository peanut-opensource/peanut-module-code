<?php

declare(strict_types=1);

namespace app\platform\controller;

use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use think\facade\Db;

/** @property-read ApiPermissionCatalog $catalog @property-read AuditService $audit */
final class PermissionApiController extends BasePlatformController
{
    protected string $catalogClass = ApiPermissionCatalog::class;
    protected string $auditClass = AuditService::class;
    protected string $menusClass = MenuAdministrationService::class;

    public function menus()
    {
        $this->fields($this->request->get(), []);
        $items = $this->menus->permissionBindings();
        return $this->data(['items' => $items, 'total' => count($items)]);
    }

    public function replaceMenuBinding()
    {
        $input = $this->fields($this->request->put(), ['menu_key', 'permission_key', 'action_key', 'expected_revision']);
        if (!array_key_exists('expected_revision', $input)) {
            throw AdminAccessException::preconditionRequired();
        }
        if (!array_key_exists('permission_key', $input) || !array_key_exists('action_key', $input)
            || !is_string($input['menu_key'] ?? null) || $input['menu_key'] === '' || strlen($input['menu_key']) > 160
            || !is_string($input['expected_revision']) || preg_match('/^[a-f0-9]{64}$/D', $input['expected_revision']) !== 1
            || ($input['permission_key'] !== null && (!is_string($input['permission_key']) || $input['permission_key'] === '' || strlen($input['permission_key']) > 160))
            || ($input['action_key'] !== null && (!is_string($input['action_key']) || trim($input['action_key']) !== $input['action_key'] || strlen($input['action_key']) > 200))) {
            throw AdminAccessException::invalid('MENU_BINDING_INVALID', 'A menu identity, permission, action and revision are required.');
        }
        $context = $this->platformContext?->core ?? throw new \DomainException('PLATFORM_CONTEXT_REQUIRED');
        return $this->data(Db::transaction(function () use ($input, $context): array {
            $result = $this->menus->replacePermissionBinding($input['menu_key'], $input['permission_key'], $input['action_key'],
                $input['expected_revision'], app(\app\common\contract\module\ModuleQualificationQuery::class));
            $this->audit->platform($context->operatorId, $context->accountId, $context->requestId,
                'permission.menu.replaced', 'platform.permission.api.manage', targetType: 'menu', targetId: $input['menu_key'], before: $result['before'], after: $result['after']);
            return $result['after'];
        }));
    }

    public function bundles()
    {
        [$input, $page, $audience, $search] = $this->listInput(false);
        return $this->data($this->catalog->bundles($page, $audience, $search)
            + ['page_no' => $page->page, 'page_size' => $page->pageSize]);
    }

    public function apis()
    {
        [$input, $page, $audience, $search] = $this->listInput(true);
        $status = $input['status'] ?? '';
        if (!in_array($status, ['', 'active', 'retired'], true)) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'Invalid API status.');
        }
        return $this->data($this->catalog->apis($page, $audience, $search, $status)
            + ['page_no' => $page->page, 'page_size' => $page->pageSize]);
    }

    public function detail()
    {
        $input = $this->fields($this->request->get(), ['permission_key']);
        return $this->data($this->catalog->bundle($this->key($input['permission_key'] ?? null)));
    }

    public function create()
    {
        $input = $this->fields($this->request->post(), ['key', 'name', 'audience']);
        $key = $this->key($input['key'] ?? null);
        $name = $this->name($input['name'] ?? null);
        $audience = $input['audience'] ?? null;
        if (!in_array($audience, ['tenant', 'platform'], true)) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'A valid audience is required.');
        }
        $context = $this->platformContext?->core ?? throw new \DomainException('PLATFORM_CONTEXT_REQUIRED');
        return $this->data(Db::transaction(function () use ($key, $name, $audience, $context): array {
            $after = $this->catalog->createCustom($key, $name, $audience);
            $this->audit->platform($context->operatorId, $context->accountId, $context->requestId,
                'permission.created', 'platform.permission.api.manage', targetType: 'permission', targetId: $key, after: $after);
            return $after;
        }));
    }

    public function update()
    {
        $input = $this->fields($this->request->put(), ['permission_key', 'name', 'status', 'expected_revision']);
        if (!isset($input['expected_revision'])) {
            throw AdminAccessException::preconditionRequired();
        }
        $key = $this->key($input['permission_key'] ?? null);
        $name = $this->name($input['name'] ?? null);
        $status = $input['status'] ?? null;
        if (!in_array($status, ['active', 'retired'], true)) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'A valid status is required.');
        }
        $revision = $this->positive($input['expected_revision']);
        $context = $this->platformContext?->core ?? throw new \DomainException('PLATFORM_CONTEXT_REQUIRED');
        return $this->data(Db::transaction(function () use ($key, $name, $status, $revision, $context): array {
            $before = $this->catalog->bundle($key);
            $after = $this->catalog->updateCustom($key, $name, $status, $revision);
            $this->audit->platform($context->operatorId, $context->accountId, $context->requestId,
                'permission.updated', 'platform.permission.api.manage', targetType: 'permission', targetId: $key, before: $before, after: $after);
            return $after;
        }));
    }

    private function name(mixed $name): string
    {
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 160) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'A bounded name is required.');
        }
        return trim($name);
    }

    public function replace()
    {
        $input = $this->fields($this->request->put(), ['permission_key', 'expected_revision', 'api_ids']);
        if (!isset($input['expected_revision'])) {
            throw AdminAccessException::preconditionRequired();
        }
        $ids = $input['api_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 2000
            || array_filter($ids, static fn($id): bool => !is_string($id) || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1)) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'A bounded list of registered API IDs is required.');
        }
        $key = $this->key($input['permission_key'] ?? null);
        $revision = $this->positive($input['expected_revision']);
        $context = $this->platformContext?->core ?? throw new \DomainException('PLATFORM_CONTEXT_REQUIRED');
        return $this->data(Db::transaction(function () use ($key, $revision, $ids, $context): array {
            $before = $this->catalog->bundle($key);
            $after = $this->catalog->replace($key, $revision, $ids, app(\PeanutAdmin\Kernel\Module\ModuleAvailability::class));
            $this->audit->platform($context->operatorId, $context->accountId, $context->requestId,
                'permission.apis.replaced', 'platform.permission.api.manage',
                targetType: 'permission', targetId: $key, before: $before, after: $after);
            return $after;
        }));
    }

    private function listInput(bool $apis): array
    {
        $input = $this->fields($this->request->get(), ['page_no', 'page_size', 'audience', 'search', ...($apis ? ['status'] : [])]);
        $audience = $input['audience'] ?? 'tenant';
        $search = $input['search'] ?? '';
        if (!in_array($audience, ['tenant', 'platform'], true) || !is_string($search) || mb_strlen($search) > 120) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'A valid audience and bounded search are required.');
        }
        return [$input, new PageRequest($this->positive($input['page_no'] ?? 1), $this->positive($input['page_size'] ?? 20)), $audience, trim($search)];
    }

    private function fields(array $input, array $allowed): array
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'Unknown request fields are rejected.');
        }
        return $input;
    }

    private function key(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 160) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'A registered permission key is required.');
        }
        return $value;
    }

    private function positive(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1)) || (int) $value < 1) {
            throw AdminAccessException::invalid('API_INPUT_INVALID', 'A positive integer is required.');
        }
        return (int) $value;
    }
}
