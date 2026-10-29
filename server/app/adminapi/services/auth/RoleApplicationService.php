<?php

declare(strict_types=1);

namespace app\adminapi\services\auth;

use app\common\http\PageResult;
use think\facade\Db;
use app\common\runtime\authorization\RoleAdministrationRuntime;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** Compatibility role API backed by native pa_role and pa_role_permission. */
final class RoleApplicationService
{
    public function __construct(private readonly RoleAdministrationRuntime $runtime) {}

    public function validationRules(string $scene): array
    {
        return $scene === 'add'
            ? ['name' => 'require|length:1,120', 'menu_keys' => 'array']
            : ['id' => 'require|integer|gt:0', 'name' => 'require|length:1,120', 'menu_keys' => 'array'];
    }

    public function lists(TenantContext $context, array $params): PageResult
    {
        $pagination = PaginationInput::from($params);
        $result = $this->service()->list($context->tenantId, $pagination->pageRequest);
        $memberCounts = $this->runtime->batchMemberCounts(
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
        return $this->compat($context, $this->service()->get($context->tenantId, $id));
    }

    public function add(TenantContext $context, array $params): bool
    {
        return (bool) Db::transaction(function () use ($context, $params): bool {
            $keys = $this->runtime->permissionKeys($context->tenantId, self::menuKeys($params));
            $service = $this->service();
            // Core 写命令以已认证的租户上下文记录操作者、审计与授权修订。
            $role = $service->create(
                $context,
                'application.admin.' . bin2hex(random_bytes(8)),
                (string) $params['name'],
                (string) ($params['desc'] ?? ''),
            );
            if ($keys !== []) {
                $service->replacePermissions($context, (int) $role['id'], $keys, (int) $role['revision']);
            }
            return true;
        });
    }

    public function edit(TenantContext $context, array $params): bool
    {
        return (bool) Db::transaction(function () use ($context, $params): bool {
            $menuKeys = self::menuKeys($params);
            $permissions = array_key_exists('menu_keys', $params) ? $this->runtime->permissionKeys($context->tenantId, $menuKeys) : null;
            $service = $this->service();
            $current = $service->get($context->tenantId, (int) $params['id']);
            $role = $service->update(
                $context,
                (int) $params['id'],
                (string) $params['name'],
                (string) ($params['desc'] ?? ''),
                (int) $current['revision'],
            );
            if (array_key_exists('menu_keys', $params)) {
                $service->replacePermissions(
                    $context,
                    (int) $params['id'],
                    $permissions,
                    (int) $role['revision'],
                );
            }
            return true;
        });
    }

    public function delete(TenantContext $context, int $id): bool
    {
        $service = $this->service();
        $role = $service->get($context->tenantId, $id);
        $service->archive($context, $id, (int) $role['revision']);
        return true;
    }

    /** @param array<int,int>|null $memberCounts Null is used only for the single-role detail projection. */
    private function compat(TenantContext $context, array $role, ?array $memberCounts = null): array
    {
        $keys = $role['permission_keys'] ?? [];
        $menus = $this->runtime->menuKeys($context, is_array($keys) ? $keys : []);
        $roleId = (int) $role['id'];
        return ['id' => $roleId, 'name' => $role['name'], 'desc' => $role['description'] ?? '', 'sort' => 0,
            'create_time' => (string) $role['created_at'], 'num' => $memberCounts === null
                ? $this->runtime->memberCount($context->tenantId, $roleId) : ($memberCounts[$roleId] ?? 0),
            'menu_keys' => $menus, 'status' => $role['status'], 'revision' => (int) $role['revision']];
    }

    /** @return list<string> */
    private static function menuKeys(array $params): array
    {
        if (array_key_exists('menu_id', $params) || array_key_exists('menu_ids', $params)) {
            throw new \DomainException('ADMIN_MENU_KEYS_REQUIRED');
        }
        $keys = $params['menu_keys'] ?? [];
        if (!is_array($keys) || !array_is_list($keys)
            || array_filter($keys, static fn(mixed $key): bool => !is_string($key) || $key === '' || strlen($key) > 160)) {
            throw new \DomainException('ADMIN_MENU_KEYS_INVALID');
        }
        return array_values(array_unique($keys));
    }

    private function service(): \PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService
    {
        return $this->runtime->service();
    }
}
