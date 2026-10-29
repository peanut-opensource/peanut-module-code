<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Policy;

use think\facade\Db;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;

/** Keeps public demo credentials and password locking out of normal deployments. */
final class DemoAccountPolicy
{
    /** @param list<string> $demoEmails */
    public function __construct(
        private readonly bool $enabled,
        private readonly array $demoEmails,
    ) {}

    /**
     * Disposable demo credential only. Normal account passwords continue to
     * use Core's stronger minimum-length policy.
     */
    private const FIXED_PASSWORD = 'peanut1234';

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return array{enabled:bool,email:string,password:string} */
    public static function publicLoginConfiguration(string $host, array $config): array
    {
        $disabled = ['enabled' => false, 'email' => '', 'password' => ''];
        if (!($config['enabled'] ?? false)) {
            return $disabled;
        }
        try {
            $host = TenantEntryBindingResolver::normalizeHost($host);
            $tenantAHost = TenantEntryBindingResolver::normalizeHost((string) ($config['tenant_a_host'] ?? ''));
            $tenantBHost = TenantEntryBindingResolver::normalizeHost((string) ($config['tenant_b_host'] ?? ''));
        } catch (\Throwable) {
            return $disabled;
        }
        if (hash_equals($tenantBHost, $host)) {
            $email = (string) ($config['tenant_b_email'] ?? '');
        } elseif (hash_equals($tenantAHost, $host)) {
            $email = (string) ($config['tenant_a_email'] ?? '');
        } else {
            return $disabled;
        }
        return ['enabled' => true, 'email' => trim($email), 'password' => (string) ($config['password'] ?? '')];
    }

    public function isDemoEmail(string $email): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $email = strtolower(trim($email));
        foreach ($this->demoEmails as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '' && hash_equals($candidate, $email)) {
                return true;
            }
        }
        return false;
    }

    public function assertPasswordChangeAllowed(int $accountId): void
    {
        if (!$this->enabled) {
            return;
        }
        $emails = Db::name('credential')->where('account_id', $accountId)
            ->where('kind', 'email_password')->where('identifier_type', 'email')->where('status', 'active')
            ->order('id')->limit(2)->column('identifier_normalized');
        foreach ($emails as $email) {
            if ($this->isDemoEmail((string) $email)) {
                throw new \DomainException('演示账号密码已锁定，不能在页面中修改');
            }
        }
    }

    public function mutationLocked(array $adminInfo, string $path): bool
    {
        if (!$this->isDemoEmail((string) ($adminInfo['username'] ?? ''))) {
            return false;
        }
        $path = strtolower(trim($path, '/'));
        foreach ([
            'menu/', 'role/', 'admin/add', 'admin/edit', 'admin/editself', 'admin/delete', 'admin/status',
            'dept/', 'jobs/', 'config/', 'setting/', 'storage/setup', 'storage/change',
            'decoration/',
        ] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function platformMutationLocked(int $accountId): bool
    {
        if (!$this->enabled || $accountId < 1) {
            return false;
        }
        $emails = Db::name('credential')->where('account_id', $accountId)
            ->where('kind', 'email_password')->where('identifier_type', 'email')->where('status', 'active')
            ->order('id')->limit(2)->column('identifier_normalized');
        foreach ($emails as $email) {
            if ($this->isDemoEmail((string) $email)) {
                return true;
            }
        }
        return false;
    }

    public function bootstrapPassword(): string
    {
        if (!$this->enabled) {
            throw new \LogicException('演示密码策略未启用');
        }
        return bin2hex(random_bytes(24)) . 'A1';
    }

    public function replaceCredentialHashes(array $emails): void
    {
        $hash = $this->credentialHash();
        foreach (array_values(array_unique(array_map('strtolower', $emails))) as $email) {
            if (trim($email) !== '') {
                Db::name('credential')->where('identifier_type', 'email')
                    ->where('kind', 'email_password')->where('status', 'active')
                    ->where('identifier_normalized', trim($email))->update([
                        'secret_hash' => $hash,
                        'failed_attempts' => 0,
                        'locked_until' => null,
                        'secret_changed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                        'revision' => Db::raw('revision+1'),
                        'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                    ]);
            }
        }
    }

    public function credentialHash(): string
    {
        if (!$this->enabled) {
            throw new \LogicException('演示密码策略未启用');
        }
        $hash = password_hash(self::FIXED_PASSWORD, PASSWORD_ARGON2ID, [
            'memory_cost' => 65_536,
            'time_cost' => 4,
            'threads' => 2,
        ]);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('演示密码摘要生成失败');
        }
        return $hash;
    }
}
