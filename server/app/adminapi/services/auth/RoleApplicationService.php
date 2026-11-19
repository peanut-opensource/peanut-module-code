<?php

declare(strict_types=1);

namespace app\adminapi\services\auth;

use app\common\http\PageResult;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;

/** Role details are independent from menu visibility and permission assignment. */
final class RoleApplicationService
{
    public function __construct(
        private readonly RoleAdminService $roleAdmin,
        private readonly TenantAuthorizationQuery $identityAuthorization,
    ) {}

    public function validationRules(string $scene): array
    {
        $id = ['require', static fn(mixed $value): bool => (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false];
        return match ($scene) {
            'add' => ['name' => 'require|length:1,120'],
            'detail', 'delete' => ['id' => $id],
            default => ['id' => $id, 'name' => 'require|length:1,120'],
        };
    }

    public function lists(TenantContext $context, array $params): PageResult
    {
        $pagination = PaginationInput::from($params);
        $result = $this->roleAdmin->list($context->tenantId, $pagination->pageRequest);
        $memberCounts = $this->identityAuthorization->batchRoleMemberCounts(
            $context->tenantId,
            array_map('intval', array_column($result['items'], 'id')),
        );
        $lists = array_map(fn(array $row): array => $this->compat($context, $row, $memberCounts), $result['items']);
        return new PageResult($lists, $result['total'], $pagination->page, $pagination->pageSize);
    }

    public function getAll(TenantContext $context): array
    {
        return self::lists($context, ['page_size' => 100])->items;
    }

    public function detail(TenantContext $context, int $id): array
    {
        return $this->compat($context, $this->roleAdmin->get($context->tenantId, $id));
    }

    public function add(TenantContext $context, array $params): bool
    {
        $this->roleAdmin->create($context, 'application.admin.' . bin2hex(random_bytes(8)),
            (string) $params['name'], (string) ($params['desc'] ?? ''));
        return true;
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $current = $this->roleAdmin->get($context->tenantId, (int) $params['id']);
        $this->roleAdmin->update($context, (int) $params['id'], (string) $params['name'],
            (string) ($params['desc'] ?? ''), (int) $current['revision']);
        return true;
    }

    public function delete(TenantContext $context, int $id): bool
    {
        $role = $this->roleAdmin->get($context->tenantId, $id);
        $this->roleAdmin->archive($context, $id, (int) $role['revision']);
        return true;
    }

    /** @param array<int,int>|null $memberCounts Null is used only for the single-role detail projection. */
    private function compat(TenantContext $context, array $role, ?array $memberCounts = null): array
    {
        $keys = $role['permission_keys'] ?? [];
        $roleId = (int) $role['id'];
        return ['id' => $roleId, 'key' => (string) $role['key'], 'name' => $role['name'], 'desc' => $role['description'] ?? '', 'sort' => 0,
            'create_time' => (string) $role['created_at'], 'num' => $memberCounts === null
                ? $this->identityAuthorization->roleMemberCount($context->tenantId, $roleId) : ($memberCounts[$roleId] ?? 0),
            'permission_keys' => $keys, 'status' => $role['status'], 'revision' => (int) $role['revision']];
    }
}
