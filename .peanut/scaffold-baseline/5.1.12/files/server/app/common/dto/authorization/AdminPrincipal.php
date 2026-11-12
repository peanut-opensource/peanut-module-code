<?php

declare(strict_types=1);

namespace app\common\dto\authorization;

final readonly class AdminPrincipal
{
    /** @param list<array<string,mixed>> $roles */
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $accountId,
        public string $tenantName,
        public string $username,
        public string $nickname,
        public string $name,
        public string $avatar,
        public bool $root,
        public int $switchableTenantCount,
        public array $roles,
        public string $roleName,
        public int $authorizationRevision,
        public ?int $primaryDepartmentId,
        public mixed $lastLoginAt,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            tenantId: (int) ($data['tenant_id'] ?? 0),
            accountId: (int) ($data['account_id'] ?? 0),
            tenantName: (string) ($data['tenant_name'] ?? ''),
            username: (string) ($data['username'] ?? $data['account'] ?? ''),
            nickname: (string) ($data['nickname'] ?? $data['name'] ?? ''),
            name: (string) ($data['name'] ?? $data['nickname'] ?? ''),
            avatar: (string) ($data['avatar'] ?? ''),
            root: (int) ($data['root'] ?? 0) === 1,
            switchableTenantCount: (int) ($data['switchable_tenant_count'] ?? 0),
            roles: is_array($data['roles'] ?? null) ? $data['roles'] : [],
            roleName: (string) ($data['role_name'] ?? ''),
            authorizationRevision: (int) ($data['authorization_revision'] ?? 0),
            primaryDepartmentId: isset($data['primary_department_id'])
                ? (int) $data['primary_department_id']
                : null,
            lastLoginAt: $data['last_login_at'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'tenant_name' => $this->tenantName,
            'account_id' => $this->accountId,
            'username' => $this->username,
            'account' => $this->username,
            'nickname' => $this->nickname,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'root' => $this->root ? 1 : 0,
            'switchable_tenant_count' => $this->switchableTenantCount,
            'disable' => 0,
            'roles' => $this->roles,
            'role_name' => $this->roleName,
            'authorization_revision' => $this->authorizationRevision,
            'primary_department_id' => $this->primaryDepartmentId,
            'last_login_at' => $this->lastLoginAt,
        ];
    }
}
