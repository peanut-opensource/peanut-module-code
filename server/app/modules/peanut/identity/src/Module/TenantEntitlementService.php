<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Module;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Module\Model\ModuleInstallation;
use think\facade\Db;
use think\facade\Cache;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationReadFence;

/** Shared commercial ceiling; it never grants a member a role or permission. */
final class TenantEntitlementService
{
    /** @var array<int,?array> */
    private array $licenseCache = [];

    /** @var array<string,list<string>> */

    /** @var array<int,array{modules:list<string>,permissions:list<string>}> */
    private array $permissionProjectionCache = [];

    public function __construct(
        private readonly CompiledModuleRegistry $registry,
        private readonly TenantModuleStateQueries $installations = new TenantModuleStateQueries(),
    ) {}

    public function license(int $tenantId, bool $lock = false, ?TenantContext $context = null): ?array
    {
        if (!$lock && ($read = TenantAuthorizationReadFence::activeRead($tenantId)) !== null) {
            return $read['license'][0] ?? null;
        }
        if (!$lock && !$this->inTransaction()) {
            if (array_key_exists($tenantId, $this->licenseCache)) {
                return $this->licenseCache[$tenantId];
            }
        }
        if ($context === null || $context->tenantId !== $tenantId || $context->tenantAuthorizationRevision < 1 || $lock) {
            $result = $this->licenseAndPlanRevision($tenantId, $lock)[0];
            if (!$lock && !$this->inTransaction()) {
                $this->licenseCache[$tenantId] = $result;
            }
            return $result;
        }
        $pdo = Db::connect()->getPdo();
        if ($pdo !== false && $pdo->inTransaction()) {
            return $this->licenseAndPlanRevision($tenantId, $lock)[0];
        }
        // The configured native store already isolates the database and environment.
        $payload = Cache::remember('identity:license:v1:' . $tenantId . ':' . $context->tenantAuthorizationRevision,
            fn(): array => $this->licenseAndPlanRevision($tenantId, false), 300);
        $license = $payload[0];
        if ($license === null) {
            return $this->licenseCache[$tenantId] = null;
        }
        // Cache storage lifetime never extends the license's effective window.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $state = $license['status'] === 'suspended' ? 'suspended'
            : (new DateTimeImmutable($license['effective_at']) > $now ? 'upcoming'
                : ($license['expires_at'] !== null && new DateTimeImmutable($license['expires_at']) <= $now ? 'expired' : 'active'));
        return $this->licenseCache[$tenantId] = [...$license, 'effective' => $state === 'active', 'state' => $state];
    }

    private function inTransaction(): bool
    {
        $pdo = Db::connect()->getPdo();
        return $pdo !== false && $pdo->inTransaction();
    }

    /** The plan revision is already selected by the license join. @return array{0:?array,1:mixed} */
    private function licenseAndPlanRevision(int $tenantId, bool $lock): array
    {
        $row = Db::name('tenant_license')->pk('id')->schema([ // @phpstan-ignore peanut.rawDbQuery (License and global plan revision are fetched by the trusted tenant ID, with caller-selected locking.)
            'id' => 'bigint', 'tenant_id' => 'bigint', 'plan_id' => 'bigint', 'status' => 'string',
            'effective_at' => 'datetime', 'expires_at' => 'datetime', 'revision' => 'bigint',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ])->alias('license')
            ->join('plan plan', 'plan.id = license.plan_id')->where('license.tenant_id', $tenantId)
            ->field('license.*,plan.code AS plan_code,plan.name AS plan_name,plan.revision AS plan_revision')
            ->lock($lock)->find();
        if ($row === null) {
            return [null, null];
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $effectiveAt = new DateTimeImmutable((string) $row['effective_at'], new DateTimeZone('UTC'));
        $expiresAt = $row['expires_at'] === null ? null : new DateTimeImmutable((string) $row['expires_at'], new DateTimeZone('UTC'));
        $state = $row['status'] === 'suspended' ? 'suspended'
            : ($effectiveAt > $now ? 'upcoming' : ($expiresAt !== null && $expiresAt <= $now ? 'expired' : 'active'));
        return [[
            'id' => (int) $row['id'], 'tenant_id' => (int) $row['tenant_id'], 'plan_id' => (int) $row['plan_id'],
            'plan_code' => (string) $row['plan_code'], 'plan_name' => (string) $row['plan_name'],
            'status' => (string) $row['status'], 'effective_at' => $effectiveAt->format('Y-m-d\TH:i:s.v\Z'),
            'expires_at' => $expiresAt?->format('Y-m-d\TH:i:s.v\Z'), 'revision' => (int) $row['revision'],
            'effective' => $state === 'active', 'state' => $state,
        ], $row['plan_revision']];
    }

    /** @return list<string> */
    public function moduleKeys(int $tenantId, bool $lock = false): array
    {
        if (!$lock && ($read = TenantAuthorizationReadFence::activeRead($tenantId)) !== null) {
            return $read['effective_modules'];
        }
        return $this->modulesForLicense($this->license($tenantId, $lock), $lock);
    }

    /** The same installed/compiled/license ceiling as moduleKeys(), projected to one module. */
    public function moduleAvailable(int $tenantId, string $moduleKey): bool
    {
        if (!in_array($moduleKey, $this->registry->moduleKeys(), true)) {
            return false;
        }
        $installation = $this->installations->installationRecord($moduleKey, readIfMissing: false);
        if ($installation !== null) {
            if ($installation['status'] !== 'active' || $installation['module_key'] !== $moduleKey) {
                return false;
            }
            if ($this->registry->isRequiredTenantFoundation($moduleKey)) {
                return true;
            }
            $query = Db::name('tenant_license')->pk('id')->schema([ // @phpstan-ignore peanut.rawDbQuery (The fast-path license query binds the same tenant as the live installation fallback.)
                'id' => 'bigint', 'tenant_id' => 'bigint', 'plan_id' => 'bigint', 'status' => 'string',
                'effective_at' => 'datetime', 'expires_at' => 'datetime', 'revision' => 'bigint',
                'created_at' => 'datetime', 'updated_at' => 'datetime',
            ])->alias('license')->where('license.tenant_id', $tenantId);
            $field = 'selection.module_key';
        } else {
            // Independent/transactional callers retain the original single live JOIN.
            $query = ModuleInstallation::alias('installation')
                ->where('installation.module_key', $moduleKey)->where('installation.status', 'active');
            if ($this->registry->isRequiredTenantFoundation($moduleKey)) {
                return $query->value('installation.module_key') === $moduleKey;
            }
            $query->join('tenant_license license', 'TRUE')->where('license.tenant_id', $tenantId);
            $field = 'installation.module_key';
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        return $query->join('plan plan', 'plan.id = license.plan_id')
            ->join('plan_module selection', 'selection.plan_id = plan.id')
            ->whereRaw('BINARY selection.module_key = ?', [$moduleKey])
            ->where('license.status', 'active')->where('license.effective_at', '<=', $now)
            ->where(static fn($query) => $query->pk('id')->whereNull('license.expires_at')->whereOr('license.expires_at', '>', $now))
            ->value($field) === $moduleKey;
    }

    /** @return list<string> */
    private function modulesForLicense(?array $license, bool $lock, ?array &$selected = null): array
    {
        if (!$lock && ($license['effective'] ?? false)) {
            $compiled = $this->registry->moduleKeys();
            $foundation = array_values(array_filter($compiled, $this->registry->isRequiredTenantFoundation(...)));
            $planId = (int) $license['plan_id'];
            return Db::name('module_installation')->alias('installation')
                ->leftJoin('plan_module selection', 'BINARY selection.module_key = BINARY installation.module_key AND selection.plan_id = ' . $planId)
                ->where('installation.status', 'active')->whereIn('installation.module_key', $compiled)
                ->where(static fn($query) => $query->whereIn('installation.module_key', $foundation)->whereNotNull('selection.module_key', 'OR'))
                ->order('installation.module_key')->column('installation.module_key');
        }
        // The locked path retains the plan membership rows, independently of deployment status.
        $selected = $license !== null && $license['effective']
            ? Db::name('plan_module')->where('plan_id', $license['plan_id'])->lock($lock)->column('module_key') : [];
        $keys = [];
        foreach ($this->registry->modules as $manifest) {
            $key = (string) $manifest->data['key'];
            if ($this->registry->isRequiredTenantFoundation($key) || in_array($key, $selected, true)) {
                $keys[] = $key;
            }
        }
        return array_values(array_map('strval', Db::name('module_installation')->where('status', 'active')
            ->whereIn('module_key', $keys)->order('module_key')->column('module_key')));
    }

    /** @return list<string> */
    public function permissionKeys(int $tenantId, bool $lock = false): array
    {
        return $this->permissionProjection($tenantId, $lock)['permissions'];
    }

    /** One call reads a license and its modules once; later calls always read live state.
     * @return array{modules: list<string>, permissions: list<string>}
     */
    public function permissionProjection(int $tenantId, bool $lock = false): array
    {
        if (!$lock && ($read = TenantAuthorizationReadFence::activeRead($tenantId)) !== null) {
            $license = $read['license'];
            return ['modules' => ($license[0]['effective'] ?? false) ? $license[2] : [],
                'permissions' => $license[3] ?? CorePermissionCatalog::TENANT];
        }
        if (!$lock && !$this->inTransaction() && isset($this->permissionProjectionCache[$tenantId])) {
            return $this->permissionProjectionCache[$tenantId];
        }
        $license = $this->license($tenantId, $lock);
        $modules = $license !== null && $license['effective'] ? $this->modulesForLicense($license, $lock) : [];
        $projection = ['modules' => $modules, 'permissions' => $this->permissionsForLicense($license, $modules, $lock)];
        if (!$lock && !$this->inTransaction()) {
            $this->permissionProjectionCache[$tenantId] = $projection;
        }
        return $projection;
    }

    /** @param list<string> $modules @return list<string> */
    private function permissionsForLicense(?array $license, array $modules, bool $lock): array
    {
        $keys = $license !== null && $license['effective']
            ? Db::name('plan_permission')->alias('detail')
                ->join('permission permission', 'permission.key = detail.permission_key')
                ->where('detail.plan_id', $license['plan_id'])->where('permission.status', 'active')
                ->whereNotLike('permission.key', 'platform.%')
                ->whereIn('permission.module_key', ['core', 'peanut.admin', 'instance.tenant', ...$modules])
                ->lock($lock)->column('permission.key') : [];
        return array_values(array_unique([...CorePermissionCatalog::TENANT, ...array_map('strval', $keys)]));
    }

    public function allowsModule(int $tenantId, string $moduleKey, ?DateTimeImmutable $now = null, bool $lock = false, ?TenantContext $context = null): bool
    {
        if ($this->registry->isRequiredTenantFoundation($moduleKey)) {
            return true;
        }
        $license = $this->license($tenantId, $lock, $context);
        if ($license === null || $license['status'] !== 'active') {
            return false;
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new DateTimeImmutable($license['effective_at']) <= $now
            && ($license['expires_at'] === null || $now < new DateTimeImmutable($license['expires_at']))
            && $this->planIncludesModule($tenantId, $license['plan_id'], $moduleKey, $lock, $context);
    }

    /** Only the plan membership fact is cached; callers still enforce the live license window. */
    public function planIncludesModule(int $tenantId, int $planId, string $moduleKey, bool $lock = false, ?TenantContext $context = null): bool
    {
        if (!$lock && ($snapshot = TenantAuthorizationReadFence::activeRead($tenantId)) !== null
            && ($snapshot['license'][0]['plan_id'] ?? null) === $planId && ($snapshot['license'][0]['effective'] ?? false)) {
            return in_array($moduleKey, $snapshot['plan_module_keys'], true);
        }
        $read = static fn(): bool => Db::name('plan_module')->pk(['plan_id', 'module_key'])
            ->schema(['plan_id' => 'bigint', 'module_key' => 'string'])
            ->where('plan_id', $planId)->where('module_key', $moduleKey)->lock($lock)->find() !== null;
        if ($context === null || $context->tenantId !== $tenantId || $context->tenantAuthorizationRevision < 1 || $lock) {
            return $read();
        }
        $pdo = Db::connect()->getPdo();
        if ($pdo !== false && $pdo->inTransaction()) {
            return $read();
        }
        return Cache::remember('identity:plan-module:v1:' . $tenantId . ':' . $context->tenantAuthorizationRevision
            . ':' . $planId . ':' . hash('sha256', $moduleKey), $read, 300);
    }

    /** A shared plan revision and the live time state invalidate existing authorization caches. */
    public function revisionFacts(int $tenantId, bool $lock = false, ?array &$planModuleKeys = null): array
    {
        [$license, $planRevision] = $this->licenseAndPlanRevision($tenantId, $lock);
        if ($license === null) {
            return [];
        }
        $modules = $this->modulesForLicense($license, $lock, $planModuleKeys);
        return [$license, $planRevision,
            $modules, $this->permissionsForLicense($license, $modules, $lock)];
    }
}
