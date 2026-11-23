<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Service;

use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Package;

/** Read-only configuration projection. Provider qualification requires evidence this installation does not collect. */
final readonly class ProviderQualificationProjection
{
    private const TENANT_PROVIDERS = [
        'peanut.notification' => ['notification', [
            'notification.email', 'notification.sms.aliyun', 'notification.sms.tencent',
        ]],
        'peanut.oauth' => ['oauth', [
            'oauth.wechat.mini-program', 'oauth.wechat.oa', 'oauth.wechat.open-pc', 'wechat.official-account',
        ]],
        'peanut.payment' => ['payment', ['payment.alipay', 'payment.wechat']],
    ];

    /**
     * @param \Closure(string): bool $isDeployed
     * @param \Closure(): string $digestKey
     * @param \Closure(): int $countStorage
     * @param \Closure(string, int, int): array $storagePage
     * @param \Closure(): int $countTenants
     * @param \Closure(int, int): list<int> $tenantPage
     * @param \Closure(list<int>, list<string>, string): list<\PeanutAdmin\Modules\Integration\Contract\ExternalBindingQualification> $bindings
     */
    public function __construct(
        private PlatformPermissionChecker $permissions,
        private \Closure $isDeployed,
        private \Closure $digestKey,
        private \Closure $countStorage,
        private \Closure $storagePage,
        private \Closure $countTenants,
        private \Closure $tenantPage,
        private \Closure $bindings,
    ) {}

    public function snapshot(PlatformContext $context, int $page, int $pageSize): array
    {
        if (!$this->permissions->allows($context, Package::READ_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
        if ($page < 1 || $page > 999999 || $pageSize < 1 || $pageSize > 100) {
            throw OpsConsoleException::invalid();
        }

        try {
            return $this->readPage($page, $pageSize);
        } catch (\Throwable) {
            throw OpsConsoleException::providerUnavailable();
        }
    }

    private function readPage(int $page, int $pageSize): array
    {
        $storageCount = ($this->isDeployed)('peanut.file') ? ($this->countStorage)() : 0;
        $providers = $this->tenantProviders();
        $tenantCount = $providers === [] ? 0 : ($this->countTenants)();
        $total = $storageCount + count($providers) * $tenantCount;
        $offset = ($page - 1) * $pageSize;
        $items = [];

        if ($offset < $total) {
            $key = ($this->digestKey)();
            if (strlen($key) < 32) {
                throw new \InvalidArgumentException('PROVIDER_QUALIFICATION_DIGEST_KEY_INVALID');
            }
            if ($offset < $storageCount) {
                $length = min($pageSize, $storageCount - $offset);
                $subjects = ($this->storagePage)($key, $offset, $length);
                if (count($subjects) !== $length) {
                    throw new \RuntimeException('PROVIDER_QUALIFICATION_PAGE_CHANGED');
                }
                foreach ($subjects as $subject) {
                    $items[] = $this->row(
                        $key, 'storage.' . $subject['driver'], 'storage', null, $subject['account_key'],
                        $subject['configured'], $subject['credential_rotated_at'],
                    );
                }
            }

            $offset = max(0, $offset - $storageCount);
            foreach ($providers as $provider => $category) {
                if (count($items) === $pageSize) {
                    break;
                }
                if ($offset >= $tenantCount) {
                    $offset -= $tenantCount;
                    continue;
                }

                $length = min($pageSize - count($items), $tenantCount - $offset);
                array_push($items, ...$this->tenantRows($key, $provider, $category, $offset, $length));
                $offset = 0;
            }
        }

        return [
            'schema_version' => 1,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'providers' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /** @return array<string, string> */
    private function tenantProviders(): array
    {
        if (!($this->isDeployed)('peanut.integration')) {
            return [];
        }

        $providers = [];
        foreach (self::TENANT_PROVIDERS as $module => [$category, $keys]) {
            if (($this->isDeployed)($module)) {
                $providers += array_fill_keys($keys, $category);
            }
        }
        return $providers;
    }

    private function tenantRows(string $key, string $provider, string $category, int $offset, int $length): array
    {
        $tenantIds = ($this->tenantPage)($offset, $length);
        $implemented = $provider !== 'notification.email';
        $bindings = $implemented ? ($this->bindings)($tenantIds, [$provider], $key) : [];
        if (count($tenantIds) !== $length || ($implemented && count($bindings) !== $length)) {
            throw new \RuntimeException('PROVIDER_QUALIFICATION_PAGE_CHANGED');
        }

        $items = [];
        foreach ($tenantIds as $index => $tenantId) {
            $binding = $bindings[$index] ?? null;
            if ($implemented && ($binding?->tenantId !== $tenantId || $binding->providerKey !== $provider)) {
                throw new \RuntimeException('PROVIDER_QUALIFICATION_SCOPE_MISMATCH');
            }
            $items[] = $this->row($key, $provider, $category, $tenantId, $provider, $binding?->configured ?? false, null);
        }
        return $items;
    }

    private function row(string $key, string $provider, string $category, ?int $tenantId, string $reference, bool $configured, ?string $rotatedAt): array
    {
        $scope = $tenantId === null ? 'instance' : 'tenant';
        $scopeKey = hash_hmac('sha256', implode("\0", [$provider, $scope, (string) ($tenantId ?? 0), $reference]), $key);
        return [
            'provider_key' => $provider,
            'category' => $category,
            'scope' => ['type' => $scope, 'key' => 'scope_' . $scopeKey],
            'configured' => $configured,
            'connected' => false,
            'callback_verified' => false,
            'credential_rotated_at' => $rotatedAt,
            'observed_at' => null,
            'expires_at' => null,
            'qualified' => false,
            'status_code' => match (true) {
                $provider === 'notification.email' => 'NOT_IMPLEMENTED',
                !$configured => 'CONFIGURATION_REQUIRED',
                default => 'CONNECTIVITY_EVIDENCE_REQUIRED',
            },
            'recent_failure' => null,
            'evidence_digest' => hash('sha256', ''),
        ];
    }
}
