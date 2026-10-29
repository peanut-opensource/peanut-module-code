<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Service;

use PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferCommands;
use PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferQueries;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\ConfigurationPackageCodec;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\ConfigurationTransferAdapter;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\ConfigurationTransferValue;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\SecretReferenceCodec;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use think\facade\Db;

/**
 * Application-owned configuration transfer facade.
 *
 * Configuration packages contain only logical, scoped values. They are
 * deliberately separate from backup artifacts and never carry Tenant IDs or
 * secret material. The application service performs all package validation
 * and conflict planning before delegating writes to the owning adapters.
 */
final class ConfigurationTransferApplicationService implements ConfigurationTransferCommands, ConfigurationTransferQueries
{
    private const TENANT_SCOPE = 'tenant';
    private const DEPLOYMENT_SCOPE = 'deployment';

    /** @var list<string> */
    private const CONFLICT_POLICIES = ['abort', 'overwrite', 'skip'];

    /** @var array<string, ConfigurationTransferAdapter> */
    private array $adapters = [];

    /**
     * @param list<ConfigurationTransferAdapter> $adapters
     */
    public function __construct(
        array $adapters,
        private readonly ConfigurationPackageCodec $codec,
        private readonly AuditContractHost $audit,
    ) {
        foreach ($adapters as $adapter) {
            if (!$adapter instanceof ConfigurationTransferAdapter) {
                throw new \InvalidArgumentException('TRANSFER_ADAPTER_INVALID');
            }
            $key = $adapter->key();
            if (!in_array($key, ConfigurationPackageCodec::ADAPTERS, true) || isset($this->adapters[$key])) {
                throw new \InvalidArgumentException('TRANSFER_ADAPTER_INVALID');
            }
            $this->adapters[$key] = $adapter;
        }
    }

    /** @return array<string, mixed> */
    public function export(TenantContext|PlatformContext $context, string $scope): array
    {
        $scope = $this->assertContext($context, $scope);
        $entries = [];
        foreach ($this->adaptersForScope($scope) as $adapter) {
            foreach ($adapter->export($context) as $entry) {
                if (!is_array($entry)) {
                    throw new \runtimeException('TRANSFER_ENTRY_INVALID');
                }
                $entries[] = $entry;
            }
        }

        $package = $this->codec->create($scope, $entries);
        $this->audit($context, $scope, 'export', (string) $package['checksum'], count($entries), 0, 0);

        return $package;
    }

    /** @return array<string, mixed> */
    public function dryRun(
        TenantContext|PlatformContext $context,
        string $scope,
        array|string $package,
        array $secretBindings = [],
        string $conflictPolicy = 'abort',
    ): array {
        $scope = $this->assertContext($context, $scope);
        $plan = $this->plan($context, $scope, $package, $secretBindings, $conflictPolicy);
        $result = $this->publicPlan($plan, true);
        $this->audit(
            $context,
            $scope,
            'dry-run',
            $plan['checksum'],
            $plan['entry_count'],
            count($plan['conflicts']),
            count($plan['missing_secret_references']),
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function apply(
        TenantContext|PlatformContext $context,
        string $scope,
        array|string $package,
        array $secretBindings = [],
        string $conflictPolicy = 'abort',
    ): array {
        $scope = $this->assertContext($context, $scope);
        $plan = $this->plan($context, $scope, $package, $secretBindings, $conflictPolicy);
        if ($plan['missing_secret_references'] !== []) {
            throw new \runtimeException('TRANSFER_SECRET_REBIND_REQUIRED');
        }
        if ($plan['blocking_conflicts'] !== []) {
            throw new \runtimeException('TRANSFER_CONFLICT');
        }

        // The package is a single logical change. Keep every adapter write and
        // the success audit in one unit of work so a later adapter or the
        // audit projection cannot leave a partially-applied package behind.
        return Db::transaction(function () use (
            $context,
            $scope,
            $secretBindings,
            $plan,
        ): array {
            $applied = [];
            $skipped = [];
            foreach ($plan['items'] as $item) {
                $action = $item['action'];
                if ($action === 'unchanged' || $action === 'skip') {
                    $skipped[] = $this->publicItem($item);
                    continue;
                }

                $seen = [];
                $value = SecretReferenceCodec::restore($item['entry']['value'], $secretBindings, $seen);
                $item['adapter']->apply(
                    $context,
                    $item['entry']['key'],
                    $value,
                    $item['entry'],
                    $item['current_revision'],
                );
                $applied[] = $this->publicItem($item);
            }

            // Audit before commit: an audit failure must abort the same unit
            // of work as the configuration writes.
            $this->audit(
                $context,
                $scope,
                'apply',
                $plan['checksum'],
                count($plan['items']),
                0,
                0,
                count($applied),
            );

            return [
                ...$this->publicPlan($plan, false),
                'status' => 'applied',
                'can_apply' => false,
                'applied' => $applied,
                'skipped' => $skipped,
                'applied_count' => count($applied),
                'skipped_count' => count($skipped),
            ];
        });
    }

    /** @return list<ConfigurationTransferAdapter> */
    private function adaptersForScope(string $scope): array
    {
        $keys = $scope === self::TENANT_SCOPE
            ? [
                ConfigurationPackageCodec::ADAPTER_TENANT_SETTINGS,
                ConfigurationPackageCodec::ADAPTER_TENANT_MODULES,
                ConfigurationPackageCodec::ADAPTER_EXTERNAL_BINDINGS,
            ]
            : [ConfigurationPackageCodec::ADAPTER_CORE_SETTINGS];
        $result = [];
        foreach ($keys as $key) {
            $adapter = $this->adapters[$key] ?? null;
            if ($adapter instanceof ConfigurationTransferAdapter) {
                $result[] = $adapter;
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function plan(
        TenantContext|PlatformContext $context,
        string $scope,
        array|string $package,
        array $secretBindings,
        string $conflictPolicy,
    ): array {
        if (!in_array($conflictPolicy, self::CONFLICT_POLICIES, true)) {
            throw new \runtimeException('TRANSFER_CONFLICT_POLICY_INVALID');
        }

        $document = $this->codec->decode($package, $scope);
        $entries = $document['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new \runtimeException('TRANSFER_ENTRY_INVALID');
        }

        $scopeAdapters = [];
        foreach ($this->adaptersForScope($scope) as $adapter) {
            $scopeAdapters[$adapter->key()] = $adapter;
        }
        $references = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \runtimeException('TRANSFER_ENTRY_INVALID');
            }
            $adapterKey = $entry['adapter'] ?? null;
            if (!is_string($adapterKey) || !isset($scopeAdapters[$adapterKey])) {
                throw new \runtimeException('TRANSFER_ADAPTER_SCOPE_INVALID');
            }
            foreach (SecretReferenceCodec::references($entry['value'] ?? null) as $reference) {
                $references[$reference['reference']] = $reference;
            }
        }
        $references = array_values($references);
        usort($references, static fn(array $left, array $right): int => strcmp($left['reference'], $right['reference']));
        $missing = SecretReferenceCodec::assertBindings($secretBindings, $references);

        $items = [];
        $conflicts = [];
        $blockingConflicts = [];
        foreach ($entries as $entry) {
            $adapter = $scopeAdapters[(string) $entry['adapter']];
            $key = (string) $entry['key'];
            $current = $adapter->current($context, $key);
            if (!is_array($current) || !is_bool($current['exists'] ?? null)) {
                throw new \runtimeException('TRANSFER_ADAPTER_STATE_INVALID');
            }
            $currentRevision = $this->revision($current['revision'] ?? null);
            $same = $current['exists'] && $this->equivalent(
                $entry['value'] ?? null,
                $current['value'] ?? null,
            );
            $hasBoundSecret = false;
            foreach (SecretReferenceCodec::references($entry['value'] ?? null) as $reference) {
                if ($reference['state'] === 'configured' && array_key_exists($reference['reference'], $secretBindings)) {
                    $hasBoundSecret = true;
                }
            }

            if (!$current['exists'] && !$adapter->supportsCreate()) {
                $action = 'conflict';
                $conflict = [
                    'adapter' => $adapter->key(),
                    'key' => $key,
                    'current_revision' => $currentRevision,
                    'action' => $action,
                ];
                $conflicts[] = $conflict;
                $blockingConflicts[] = $conflict;
            } elseif (!$current['exists']) {
                $action = 'create';
            } elseif ($same && $hasBoundSecret) {
                $action = 'replace-secret';
            } elseif ($same) {
                $action = 'unchanged';
            } else {
                $action = match ($conflictPolicy) {
                    'overwrite' => 'replace',
                    'skip' => 'skip',
                    default => 'conflict',
                };
                $conflict = [
                    'adapter' => $adapter->key(),
                    'key' => $key,
                    'current_revision' => $currentRevision,
                    'action' => $action,
                ];
                $conflicts[] = $conflict;
                if ($action === 'conflict') {
                    $blockingConflicts[] = $conflict;
                }
            }

            $items[] = [
                'adapter' => $adapter,
                'entry' => $entry,
                'action' => $action,
                'current_exists' => $current['exists'],
                'current_revision' => $currentRevision,
            ];
        }

        return [
            'scope' => $scope,
            'checksum' => (string) $document['checksum'],
            'conflict_policy' => $conflictPolicy,
            'items' => $items,
            'entry_count' => count($items),
            'conflicts' => $conflicts,
            'blocking_conflicts' => $blockingConflicts,
            'missing_secret_references' => $missing,
        ];
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function publicPlan(array $plan, bool $dryRun): array
    {
        $items = array_map(fn(array $item): array => $this->publicItem($item), $plan['items']);
        $counts = [
            'total' => count($items),
            'create' => 0,
            'replace' => 0,
            'unchanged' => 0,
            'skip' => 0,
            'conflict' => 0,
        ];
        foreach ($items as $item) {
            $action = (string) $item['action'];
            if ($action === 'replace-secret') {
                ++$counts['replace'];
            } elseif (isset($counts[$action])) {
                ++$counts[$action];
            }
        }
        return [
            'protocol' => ConfigurationPackageCodec::PROTOCOL,
            'schema_version' => ConfigurationPackageCodec::SCHEMA_VERSION,
            'scope' => $plan['scope'],
            'checksum' => $plan['checksum'],
            'dry_run' => $dryRun,
            'status' => $plan['blocking_conflicts'] !== [] || $plan['missing_secret_references'] !== []
                ? 'blocked'
                : 'ready',
            'can_apply' => $plan['blocking_conflicts'] === [] && $plan['missing_secret_references'] === [],
            'conflict_policy' => $plan['conflict_policy'],
            'counts' => $counts,
            'entries' => $items,
            'conflicts' => array_map(static fn(array $conflict): array => [
                ...$conflict,
                'current_revision' => $conflict['current_revision'] === null
                    ? null
                    : (string) $conflict['current_revision'],
            ], $plan['conflicts']),
            'missing_secret_references' => $plan['missing_secret_references'],
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function publicItem(array $item): array
    {
        $entry = $item['entry'];
        $secrets = [];
        foreach (SecretReferenceCodec::references($entry['value'] ?? null) as $reference) {
            $secrets[] = [
                'reference' => $reference['reference'],
                'state' => $reference['state'],
            ];
        }
        return [
            'adapter' => $entry['adapter'],
            'key' => $entry['key'],
            'action' => $item['action'],
            'exists' => $item['current_exists'],
            'current_revision' => $item['current_revision'] === null
                ? null
                : (string) $item['current_revision'],
            'secrets' => $secrets,
        ];
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        try {
            return json_encode(
                ConfigurationTransferValue::comparable($left),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) === json_encode(
                ConfigurationTransferValue::comparable($right),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException) {
            throw new \runtimeException('TRANSFER_ENTRY_INVALID');
        }
    }

    private function revision(mixed $revision): ?int
    {
        if ($revision === null) {
            return null;
        }
        if (is_int($revision)) {
            return $revision >= 0 ? $revision : throw new \runtimeException('TRANSFER_ADAPTER_STATE_INVALID');
        }
        if (is_string($revision) && preg_match('/^[0-9]+$/D', $revision) === 1) {
            return (int) $revision;
        }
        throw new \runtimeException('TRANSFER_ADAPTER_STATE_INVALID');
    }

    private function assertContext(TenantContext|PlatformContext $context, string $scope): string
    {
        if (!in_array($scope, [self::TENANT_SCOPE, self::DEPLOYMENT_SCOPE], true)) {
            throw new \runtimeException('TRANSFER_SCOPE_INVALID');
        }
        if ($scope === self::TENANT_SCOPE) {
            if (!$context instanceof TenantContext
                || $context->tenantId < 1
                || $context->accountId < 1
                || $context->memberId < 1
                || $context->authorizationRevision < 1
                || $context->sessionKey === ''
                || $context->clientKey === ''
                || $context->requestId === '') {
                throw new \runtimeException('TRANSFER_TENANT_CONTEXT_INVALID');
            }
            return self::TENANT_SCOPE;
        }
        if (!$context instanceof PlatformContext
            || $context->accountId < 1
            || $context->operatorId < 1
            || $context->sessionKey === ''
            || $context->clientKey !== 'platform-web'
            || $context->requestId === '') {
            throw new \runtimeException('TRANSFER_DEPLOYMENT_CONTEXT_INVALID');
        }
        return self::DEPLOYMENT_SCOPE;
    }

    private function audit(
        TenantContext|PlatformContext $context,
        string $scope,
        string $operation,
        string $checksum,
        int $entryCount,
        int $conflictCount,
        int $missingSecretCount,
        int $appliedCount = 0,
    ): void {
        $metadata = [
            'scope' => $scope,
            'checksum' => $checksum,
            'entry_count' => $entryCount,
            'conflict_count' => $conflictCount,
            'missing_secret_count' => $missingSecretCount,
            'applied_count' => $appliedCount,
        ];
        if ($context instanceof TenantContext) {
            $this->audit->appendTenantMember(
                $context,
                'tenant.configuration.transfer.' . $operation,
                'configuration.transfer.' . $operation,
                'configuration-package',
                $checksum,
                null,
                null,
                $entryCount,
                hash('sha256', $checksum),
                $metadata,
            );
            return;
        }
        $this->audit->recordPlatform(
            'platform.configuration.transfer.' . $operation,
            'configuration.transfer.' . $operation,
            $context->requestId,
            $context->operatorId,
            $context->accountId,
            $metadata,
            AuditOutcome::Success,
            null,
        );
    }

}
