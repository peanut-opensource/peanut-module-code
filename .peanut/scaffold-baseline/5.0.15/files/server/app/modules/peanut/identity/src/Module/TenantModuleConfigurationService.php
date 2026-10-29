<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Module;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Module\Model\TenantModule;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use RuntimeException;
use think\db\Raw;
use think\facade\Db;

final readonly class TenantModuleConfigurationService
{
    public function __construct(
        private \PeanutAdmin\Kernel\Module\CompiledModuleRegistry $registry,
        private \PeanutAdmin\Kernel\Module\TenantModuleConfigValidator $validator,
        private \PeanutAdmin\Kernel\Module\ModuleRuntimeRepository $modules,
        private AuditService $audit,
    ) {}

    /** @return list<array{module_key:string,exists:bool,config:array<string,mixed>,revision:int}> */
    public function transferSnapshot(TenantContext $actor): array
    {
        $this->assertTransferContext($actor);
        // Use one application UTC instant, as current-state evaluation and ModuleGuard do.
        $now = $this->now();
        $instant = new DateTimeImmutable($now, new DateTimeZone('UTC'));
        $states = [];
        foreach (Db::name('tenant_module')->where('tenant_id', $actor->tenantId)->where('status', 'enabled')
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_at')->whereOr('effective_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', $now);
            })
            ->field('module_key,status,config_json,config_revision,effective_at,expires_at')->order('module_key')->select()->toArray() as $row) {
            if ($this->transferEffective($row, $instant)) {
                $states[] = $this->transferState((string) $row['module_key'], $row, $instant);
            }
        }
        return $states;
    }

    /** @return null|array{module_key:string,exists:bool,config:array<string,mixed>,revision:int} */
    public function transferCurrent(TenantContext $actor, string $moduleKey): ?array
    {
        $this->assertTransferContext($actor);
        $this->assertTransferKey($moduleKey);
        $row = Db::name('tenant_module')->where('tenant_id', $actor->tenantId)->where('module_key', $moduleKey)
            ->field('status,config_json,config_revision,effective_at,expires_at')->find();
        return is_array($row) ? $this->transferState($moduleKey, $row, new DateTimeImmutable('now', new DateTimeZone('UTC'))) : null;
    }

    /** @param array<string,mixed> $row @return array{module_key:string,exists:bool,config:array<string,mixed>,revision:int} */
    private function transferState(string $moduleKey, array $row, DateTimeImmutable $now): array
    {
        $this->assertTransferKey($moduleKey);
        $encoded = $row['config_json'] ?? null;
        try {
            $config = $encoded === null || $encoded === '' ? [] : json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('TRANSFER_TENANT_MODULE_INVALID');
        }
        if (!is_array($config)) {
            throw new RuntimeException('TRANSFER_TENANT_MODULE_INVALID');
        }
        return ['module_key' => $moduleKey, 'exists' => $this->transferEffective($row, $now), 'config' => $config, 'revision' => (int) ($row['config_revision'] ?? 0)];
    }

    private function transferEffective(array $row, DateTimeImmutable $now): bool
    {
        if (($row['status'] ?? null) !== 'enabled') {
            return false;
        }
        foreach (['effective_at' => true, 'expires_at' => false] as $column => $lowerBound) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $date = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
            } catch (\Throwable) {
                return false;
            }
            if (($lowerBound && $date > $now) || (!$lowerBound && $date <= $now)) {
                return false;
            }
        }
        return true;
    }

    private function assertTransferContext(TenantContext $actor): void
    {
        if ($actor->tenantId < 1 || $actor->accountId < 1 || $actor->memberId < 1
            || $actor->authorizationRevision < 1 || $actor->sessionKey === ''
            || $actor->clientKey === '' || $actor->requestId === '') {
            throw new RuntimeException('TRANSFER_TENANT_CONTEXT_INVALID');
        }
    }

    private function assertTransferKey(string $moduleKey): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/D', $moduleKey) !== 1) {
            throw new RuntimeException('TRANSFER_TENANT_MODULE_INVALID');
        }
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function update(
        TenantContext $actor,
        string $moduleKey,
        array $config,
        int $expectedRevision,
    ): array {
        $manifest = $this->manifest($moduleKey);
        $this->validator->assertValid($manifest, $config);
        $guard = new \PeanutAdmin\Kernel\Module\ModuleGuard($this->modules);
        $guard->assertDeployment($moduleKey);
        $guard->assertTenant($actor->tenantId, $moduleKey, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return Db::transaction(function () use ($actor, $moduleKey, $config, $expectedRevision): array {
            Tenant::where('id', $actor->tenantId)->lock(true)->find();
            $current = $this->row($actor->tenantId, $moduleKey, true);
            if ((int) $current['config_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            try {
                $configJson = json_encode((object) $config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                throw new \PeanutAdmin\Kernel\Module\ModuleException('MODULE_CONFIG_INVALID', 'Module configuration is not valid JSON.');
            }
            $now = $this->now();
            if (TenantModule::where('tenant_id', $actor->tenantId)
                ->where('module_key', $moduleKey)
                ->where('status', 'enabled')
                ->where('config_revision', $expectedRevision)
                ->update([
                    'config_json' => $configJson,
                    'config_revision' => new Raw('config_revision + 1'),
                    'authorization_revision' => new Raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            Tenant::where('id', $actor->tenantId)->update([
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'revision' => new Raw('revision + 1'),
                'updated_at' => $now,
            ]);
            $this->audit->tenantMember(
                context: $actor,
                eventType: 'tenant.module.configured',
                action: 'core.module.configure',
                targetResourceType: 'tenant-module',
                targetResourceId: $moduleKey,
            );

            return $this->normalize($this->row($actor->tenantId, $moduleKey));
        });
    }

    private function manifest(string $moduleKey): \PeanutAdmin\Kernel\Module\ManifestDocument
    {
        foreach ($this->registry->modules as $manifest) {
            if (($manifest->data['key'] ?? null) === $moduleKey) {
                return $manifest;
            }
        }

        throw new \PeanutAdmin\Kernel\Module\ModuleException('MODULE_NOT_INSTALLED', "Unknown module: {$moduleKey}");
    }

    /** @return array<string, mixed> */
    private function row(int $tenantId, string $moduleKey, bool $forUpdate = false): array
    {
        $query = TenantModule::where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->field([
            'module_key', 'status', 'source', 'config_json' => 'stored_config_json',
            'config_revision', 'authorization_revision', 'effective_at', 'expires_at',
            'enabled_at', 'disabled_at',
        ])->find()?->toArray();

        return $row ?? throw new \PeanutAdmin\Kernel\Module\ModuleException(
            'MODULE_TENANT_DISABLED',
            "Module {$moduleKey} is disabled for tenant.",
        );
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        try {
            $config = json_decode((string) ($row['stored_config_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored module configuration is invalid.', 0, $exception);
        }

        return [
            'module_key' => (string) $row['module_key'],
            'status' => (string) $row['status'],
            'source' => (string) $row['source'],
            'config' => is_array($config) ? $config : [],
            'revision' => (string) $row['config_revision'],
            'authorization_revision' => (string) $row['authorization_revision'],
            'effective_at' => $row['effective_at'],
            'expires_at' => $row['expires_at'],
            'enabled_at' => $row['enabled_at'],
            'disabled_at' => $row['disabled_at'],
        ];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
