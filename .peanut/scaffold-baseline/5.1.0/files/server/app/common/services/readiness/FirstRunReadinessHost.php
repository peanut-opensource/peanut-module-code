<?php

declare(strict_types=1);

namespace app\common\services\readiness;

use PeanutAdmin\Modules\Notification\Contract\NotificationQueries;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\File\Contract\StorageConfiguration;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Ops\Contract\InstanceSafetyQueries;

/**
 * Read-only first-run readiness projection.
 *
 * A configured value is not promoted to external connectivity or production
 * qualification. Instance details and credentials never leave this Host.
 */
final class FirstRunReadinessHost
{
    public function __construct(
        private readonly CoreTenantModuleAdminBridge $modules,
        private readonly StorageConfiguration $storage,
        private readonly WebsiteConfigService $website,
        private readonly InstanceSafetyQueries $instanceSafety,
        private readonly PasswordPolicy $passwords,
        private readonly ?NotificationQueries $notifications = null,
    ) {}

    /** @return array{production_ready:bool,summary:array<string,int>,items:list<array<string,mixed>>} */
    public function checklist(
        AuthenticatedMemberContext|TenantContext $context,
        string $requestOrigin,
        string $deploymentMode,
    ): array {
        $tenantId = $context->tenantId;
        $registeredPermissions = $this->modules->registeredPermissions($tenantId);
        $notificationEnabled = in_array(
            'peanut.notification.channel.detail',
            $registeredPermissions,
            true,
        );
        $taskEnabled = in_array('peanut.task.list', $registeredPermissions, true);

        $items = [
            $this->brand($context),
            $this->notification($notificationEnabled),
            $this->storage($deploymentMode),
            $this->backup($deploymentMode),
            $this->worker($deploymentMode, $taskEnabled),
            $this->domainTls($requestOrigin, $deploymentMode),
            $this->accountSecurity(),
        ];

        $summary = [
            'configured' => 0,
            'observed' => 0,
            'action_required' => 0,
            'unverified' => 0,
            'not_implemented' => 0,
            'production_blockers' => 0,
        ];
        foreach ($items as $item) {
            $status = (string) $item['status'];
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
            if ((bool) $item['production_blocking']) {
                $summary['production_blockers']++;
            }
        }

        return [
            'production_ready' => $summary['production_blockers'] === 0,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    private function brand(AuthenticatedMemberContext|TenantContext $context): array
    {
        $defaults = WebsiteConfigService::defaults();
        $website = $this->website->get($context);
        $requiredFields = ['name', 'web_logo', 'web_favicon', 'shop_name', 'pc_title'];
        $complete = $this->fieldsPresent($website, $requiredFields);
        $customized = false;
        foreach ($defaults as $field => $default) {
            if ((string) ($website[$field] ?? '') !== $default) {
                $customized = true;
                break;
            }
        }

        return $this->item(
            'brand',
            'tenant',
            $complete && $customized ? 'configured' : 'action_required',
            'configuration_only',
            !$complete,
            $this->routeEntry('/app-setting/website', 'tenant_admin'),
            ['complete' => $complete, 'customized' => $customized],
        );
    }

    private function notification(bool $moduleEnabled): array
    {
        $smsConfigured = false;
        if ($moduleEnabled) {
            $detail = ($this->notifications ?? throw new \LogicException('NOTIFICATION_MODULE_UNAVAILABLE'))->channelDetail();
            $smsConfigured = (bool) ($detail['status']['sms'] ?? false);
        }

        return $this->item(
            'notification',
            'tenant',
            $moduleEnabled && $smsConfigured ? 'configured' : 'action_required',
            'configuration_only',
            false,
            $moduleEnabled
                ? $this->routeEntry('/notice/channel', 'tenant_admin')
                : $this->ownerEntry('platform_operator'),
            [
                'module_enabled' => $moduleEnabled,
                'sms_configured' => $smsConfigured,
                'email_provider_available' => false,
                'connectivity_verified' => false,
            ],
        );
    }

    private function storage(string $deploymentMode): array
    {
        try {
            $snapshot = $this->storage->snapshot();
            $configured = $this->defaultStorageRoutesConfigured($snapshot);
        } catch (\Throwable) {
            $configured = false;
        }

        return $this->item(
            'storage',
            'instance',
            $configured ? 'configured' : 'action_required',
            'configuration_only',
            !$configured,
            $this->ownerEntry($this->instanceOwner($deploymentMode)),
            [
                'default_public_route' => $configured,
                'default_private_route' => $configured,
                'connectivity_verified' => false,
            ],
        );
    }

    private function backup(string $deploymentMode): array
    {
        $ledgerAvailable = false;
        $verifiedAt = null;
        try {
            $value = $this->instanceSafety->lastVerifiedBackupAt();
            $ledgerAvailable = true;
            $verifiedAt = is_string($value) && $value !== ''
                ? (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                    ->format('Y-m-d\TH:i:s.v\Z')
                : null;
        } catch (\Throwable) {
            $ledgerAvailable = false;
            $verifiedAt = null;
        }

        return $this->item(
            'backup',
            'instance',
            $verifiedAt !== null ? 'unverified' : 'action_required',
            $verifiedAt !== null ? 'restore_verification_required' : 'no_verified_backup',
            true,
            $this->ownerEntry($this->instanceOwner($deploymentMode)),
            [
                'application_ledger_available' => $ledgerAvailable,
                'backup_verified' => $verifiedAt !== null,
                'last_verified_at' => $verifiedAt,
                'restore_verified' => false,
            ],
        );
    }

    private function worker(string $deploymentMode, bool $taskModuleEnabled): array
    {
        return $this->item(
            'worker',
            'instance',
            $taskModuleEnabled ? 'unverified' : 'action_required',
            'no_authoritative_heartbeat',
            $taskModuleEnabled,
            $this->ownerEntry($this->instanceOwner($deploymentMode)),
            [
                'task_module_enabled' => $taskModuleEnabled,
                'heartbeat_available' => false,
            ],
        );
    }

    private function domainTls(string $requestOrigin, string $deploymentMode): array
    {
        $scheme = strtolower((string) parse_url($requestOrigin, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($requestOrigin, PHP_URL_HOST));
        $https = $scheme === 'https';
        $publicHost = $this->publicHost($host);
        $observed = $https && $publicHost;

        return $this->item(
            'domain_tls',
            'instance',
            $observed ? 'observed' : 'action_required',
            'current_request_only',
            !$observed,
            $this->ownerEntry($this->instanceOwner($deploymentMode)),
            [
                'current_entry_https' => $https,
                'current_entry_public_host' => $publicHost,
                'all_domains_verified' => false,
            ],
        );
    }

    private function accountSecurity(): array
    {
        return $this->item(
            'account_security',
            'tenant',
            'configured',
            'policy_only',
            false,
            $this->routeEntry('/user/setting', 'tenant_admin'),
            [
                'minimum_password_length' => $this->passwords->minimumLength(),
                'maximum_password_length' => $this->passwords->maximumLength(),
                'password_length_unit' => 'utf8_bytes',
                'login_attempt_lock_enabled' => true,
                'mfa_available' => false,
                'credential_strength_verified' => false,
            ],
        );
    }

    /** @param array<string,mixed> $facts */
    private function item(
        string $key,
        string $scope,
        string $status,
        string $verificationLevel,
        bool $productionBlocking,
        array $entry,
        array $facts,
    ): array {
        return [
            'key' => $key,
            'scope' => $scope,
            'status' => $status,
            'verification_level' => $verificationLevel,
            'impact_key' => "readiness.items.{$key}.impact",
            'action_key' => "readiness.items.{$key}.action",
            'entry' => $entry,
            'production_blocking' => $productionBlocking,
            'facts' => $facts,
        ];
    }

    private function routeEntry(string $route, string $audience): array
    {
        return ['kind' => 'route', 'route' => $route, 'audience' => $audience];
    }

    private function ownerEntry(string $audience): array
    {
        return ['kind' => 'owner', 'route' => null, 'audience' => $audience];
    }

    /** @param array<string,mixed> $values @param list<string> $fields */
    private function fieldsPresent(array $values, array $fields): bool
    {
        foreach ($fields as $field) {
            if (trim((string) ($values[$field] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $snapshot */
    private function defaultStorageRoutesConfigured(array $snapshot): bool
    {
        $accounts = [];
        foreach ((array) ($snapshot['accounts'] ?? []) as $account) {
            if (is_array($account)) {
                $accounts[(int) ($account['id'] ?? 0)] = $account;
            }
        }
        $spaces = [];
        foreach ((array) ($snapshot['spaces'] ?? []) as $space) {
            if (is_array($space)) {
                $spaces[(int) ($space['id'] ?? 0)] = $space;
            }
        }

        $ready = ['default.public' => false, 'default.private' => false];
        foreach ((array) ($snapshot['routes'] ?? []) as $route) {
            if (!is_array($route)) {
                continue;
            }
            $key = (string) ($route['route_key'] ?? '');
            if (!array_key_exists($key, $ready)) {
                continue;
            }
            $space = $spaces[(int) ($route['space_id'] ?? 0)] ?? null;
            $account = is_array($space)
                ? ($accounts[(int) ($space['account_id'] ?? 0)] ?? null)
                : null;
            $expectedAccess = substr($key, strlen('default.'));
            $ready[$key] = is_array($space)
                && is_array($account)
                && (string) ($route['access_type'] ?? '') === $expectedAccess
                && (string) ($space['access_type'] ?? '') === $expectedAccess
                && (string) ($space['status'] ?? '') === 'active'
                && (string) ($account['status'] ?? '') === 'active';
        }
        return !in_array(false, $ready, true);
    }

    private function publicHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return str_contains($host, '.');
        }
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function instanceOwner(string $deploymentMode): string
    {
        return strtolower(trim($deploymentMode)) === 'multi-tenant'
            ? 'platform_operator'
            : 'deployment_owner';
    }
}
