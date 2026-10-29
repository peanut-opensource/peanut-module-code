<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use think\db\Query;
use think\facade\Db;

/** 平台目录只读入口；先用原生授权器复核动作，再执行固定字段、排序和数据库分页。 */
final readonly class PlatformDirectoryQueries
{
    public function __construct(private PlatformAuthorizationEvaluator $authorization) {}

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function operators(PlatformContext $context, PageRequest $page): array
    {
        $this->authorization->assertAllowed($context, 'platform.operator.read');
        $query = Db::name('platform_operator')->alias('operator')
            ->join('account account', 'account.id=operator.account_id')
            ->leftJoin('credential credential', "credential.account_id=account.id AND credential.identifier_type='email' AND credential.status='active'")
            ->leftJoin('platform_operator_role membership', 'membership.platform_operator_id=operator.id')
            ->leftJoin('platform_role role', 'role.id=membership.platform_role_id')
            ->field('operator.id,operator.account_id,operator.display_name,operator.status,operator.security_revision,operator.created_at,operator.updated_at')
            ->field('account.display_name AS account_display_name,account.status AS account_status,credential.identifier_normalized AS email')
            ->fieldRaw("COALESCE(GROUP_CONCAT(DISTINCT role.`key` ORDER BY role.`key` SEPARATOR ','),'') AS role_keys")
            ->group('operator.id,operator.account_id,operator.display_name,operator.status,operator.security_revision,account.display_name,account.status,credential.identifier_normalized,operator.created_at,operator.updated_at')
            ->order('operator.id', 'desc');
        return $this->paginate($query, $page, (int) Db::name('platform_operator')->count(), static function (array $row): array {
            $row['role_keys'] = $row['role_keys'] === '' ? [] : explode(',', (string) $row['role_keys']);
            return $row;
        });
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function roles(PlatformContext $context, PageRequest $page): array
    {
        $this->authorization->assertAllowed($context, 'platform.role.read');
        $query = Db::name('platform_role')->alias('role')
            ->leftJoin('platform_role_permission binding', 'binding.platform_role_id=role.id')
            ->leftJoin('permission permission', "permission.id=binding.permission_id AND permission.status='active'")
            ->field('role.id,role.key,role.name,role.description,role.is_builtin,role.status,role.revision,role.created_at,role.updated_at')
            ->fieldRaw('COUNT(DISTINCT binding.permission_id) AS permission_count')
            ->fieldRaw("COALESCE(GROUP_CONCAT(DISTINCT permission.`key` ORDER BY permission.`key` SEPARATOR ','),'') AS permission_keys")
            ->group('role.id,role.key,role.name,role.description,role.is_builtin,role.status,role.revision,role.created_at,role.updated_at')
            ->order('role.id', 'desc');
        return $this->paginate($query, $page, (int) Db::name('platform_role')->count(), static function (array $row): array {
            $row['permission_keys'] = $row['permission_keys'] === '' ? [] : explode(',', (string) $row['permission_keys']);
            return $row;
        });
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function permissions(PlatformContext $context, PageRequest $page): array
    {
        $this->authorization->assertAllowed($context, 'platform.permission.read');
        $query = Db::name('permission')->where('module_key', 'platform');
        return $this->paginate((clone $query)
            ->field('id,key,module_key,type,name,description,risk_level,status,manifest_version,created_at,updated_at,retired_at')
            ->order('id'), $page, (int) $query->count());
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function audit(PlatformContext $context, PageRequest $page): array
    {
        $this->authorization->assertAllowed($context, 'platform.audit.read');
        $result = $this->paginate(Db::name('platform_audit_event')
            ->field('id,event_type,action,outcome,reason_code,operator_id,account_id,target_type,target_id,request_id,operation_id,ip_address,user_agent_hash,before_json,after_json,metadata_json,occurred_at')
            ->order('id', 'desc'), $page, (int) Db::name('platform_audit_event')->count());
        foreach ($result['items'] as &$item) {
            foreach (['before_json', 'after_json', 'metadata_json'] as $column) {
                $item[$column] = $this->decodeJson($item[$column] ?? null);
            }
        }
        unset($item);
        return $result;
    }

    /** 平台资料查看保留失效状态以便管理，不能用于证明当前所有者可执行写入。 @return array<string,mixed> */
    public function owner(PlatformContext $context, int $tenantId): array
    {
        $this->authorization->assertAllowed($context, 'platform.tenant.read');
        $row = Db::name('tenant_member')->alias('member')
            ->join('account account', 'account.id=member.account_id')
            ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner'")
            ->leftJoin('credential credential', "credential.account_id=account.id AND credential.identifier_type='email' AND credential.status='active'")
            ->where('member.tenant_id', $tenantId)
            ->field('member.id AS member_id,member.tenant_id,member.account_id,member.display_name,member.status AS member_status,member.security_revision,member.authorization_revision,member.joined_at,member.created_at,member.updated_at')
            ->field('account.display_name AS account_display_name,account.status AS account_status,credential.identifier_normalized AS email,role.id AS role_id,role.key AS role_key')
            ->order('member.id')->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        return $row;
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    private function paginate(Query $query, PageRequest $page, int $total, ?callable $map = null): array
    {
        $items = $query->limit($page->offset(), $page->pageSize)->select()->toArray();
        if ($map !== null) {
            $items = array_map($map, $items);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    private function decodeJson(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
