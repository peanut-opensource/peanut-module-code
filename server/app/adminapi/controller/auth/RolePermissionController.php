<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;

/** @property-read RoleAdminService $roles */
final class RolePermissionController extends BaseAdminController
{
    protected string $rolesClass = RoleAdminService::class;

    public function detail()
    {
        $input = $this->fields($this->request->get(), ['role_id']);
        return $this->data($this->project($this->roles->get(
            $this->tenantAdminContext()->tenantId,
            $this->positive($input['role_id'] ?? null),
        )));
    }

    public function replace()
    {
        $input = $this->fields($this->request->put(), ['role_id', 'expected_revision', 'permission_keys']);
        if (!isset($input['expected_revision'])) {
            throw AdminAccessException::preconditionRequired();
        }
        $keys = $input['permission_keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > 1000
            || array_filter($keys, static fn(mixed $key): bool => !is_string($key) || $key === '' || strlen($key) > 160)) {
            throw AdminAccessException::invalid('ROLE_PERMISSION_INPUT_INVALID', 'A list of registered permission keys is required.');
        }
        return $this->data($this->project($this->roles->replacePermissions(
            $this->tenantAdminContext(),
            $this->positive($input['role_id'] ?? null),
            $keys,
            $this->positive($input['expected_revision']),
        )));
    }

    public function catalog()
    {
        $input = $this->fields($this->request->get(), ['page_no', 'page_size', 'search']);
        $search = $input['search'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 120) {
            throw AdminAccessException::invalid('ROLE_PERMISSION_INPUT_INVALID', 'A bounded permission search is required.');
        }
        $page = new PageRequest($this->positive($input['page_no'] ?? 1), $this->positive($input['page_size'] ?? 20));
        return $this->data($this->roles->permissionCatalog($this->tenantAdminContext()->tenantId, $page, trim($search))
            + ['page_no' => $page->page, 'page_size' => $page->pageSize]);
    }

    private function project(array $role): array
    {
        return ['role_id' => $role['id'], 'key' => $role['key'], 'revision' => $role['revision'],
            'permission_keys' => $role['permission_keys']];
    }

    private function fields(array $input, array $allowed): array
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw AdminAccessException::invalid('ROLE_PERMISSION_INPUT_INVALID', 'Unknown permission request fields are rejected.');
        }
        return $input;
    }

    private function positive(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1)) || (int) $value < 1) {
            throw AdminAccessException::invalid('ROLE_PERMISSION_INPUT_INVALID', 'A positive integer is required.');
        }
        return (int) $value;
    }
}
