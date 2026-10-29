<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use think\facade\Db;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;

/** 固定的租户模块状态投影，不返回配置正文或授权能力；安装清单与必需基础模块仍由原部署登记器决定。 */
final readonly class TenantModuleStateQueries
{
    /** Lifecycle config projection; does not grant availability or any business permission. */
    public function acceptsNewOperations(int $tenantId, string $moduleKey, bool $lock = false): bool
    {
        $row = Db::name('tenant_module')->where('tenant_id', $tenantId)->where('module_key', $moduleKey)->lock($lock)->field('status,config_json')->find();
        if ($row === null || $row['status'] !== 'enabled') {
            return false;
        }
        $config = $row['config_json'] === null ? [] : (is_array($row['config_json']) ? $row['config_json'] : json_decode((string) $row['config_json'], true, 128, JSON_THROW_ON_ERROR));
        return ($config['lifecycle'] ?? 'running') === 'running';
    }
    /** Lock only the selected tenant dependency closure, before any tenant rows. @param list<string> $moduleKeys */
    public function lockTenantMutation(CompiledModuleRegistry $registry, array $moduleKeys): void
    {
        $selected = [];
        $visit = function (string $key) use (&$visit, &$selected, $registry): void {
            if (isset($selected[$key])) {
                return;
            }
            $selected[$key] = true;
            $manifest = $registry->requireManifest($key);
            foreach ($manifest->data['tenant']['requires'] ?? [] as $dependency) {
                $visit($dependency);
            }
        };
        foreach ($moduleKeys as $key) {
            $visit($key);
        }
        $this->lockLifecycleRows(array_keys($selected));
    }

    /**
     * Transaction-owned availability boundary: sorted package rows, then sorted installation rows.
     * Missing rows remain missing; the deployment identity check still rejects opening them.
     * @param list<string> $moduleKeys @param list<string> $packageKeys
     */
    public function lockLifecycleRows(array $moduleKeys, array $packageKeys = []): void
    {
        if ($moduleKeys !== []) {
            $packageKeys = [...$packageKeys, ...Db::name('plugin_module')->whereIn('module_key', $moduleKeys)->column('plugin_key')];
        }
        $packageKeys = array_values(array_unique($packageKeys));
        sort($packageKeys, SORT_STRING);
        foreach ($packageKeys as $key) {
            Db::name('plugin_installation')->where('plugin_key', $key)->lock(true)->find();
        }
        $moduleKeys = array_values(array_unique($moduleKeys));
        sort($moduleKeys, SORT_STRING);
        foreach ($moduleKeys as $key) {
            Db::name('module_installation')->where('module_key', $key)->lock(true)->find();
        }
    }

    /** Current locking read after availability locks; includes disabled rows and insertion gaps. @param list<string> $moduleKeys @return list<string> */
    public function enabledReferencesForUpdate(array $moduleKeys): array
    {
        if ($moduleKeys === []) {
            return [];
        }
        $rows = Db::name('tenant_module')->whereIn('module_key', $moduleKeys)
            ->order('module_key')->order('tenant_id')->lock(true)->field('module_key,status')->select()->toArray();
        return array_values(array_map(
            static fn(array $row): string => (string) $row['module_key'],
            array_filter($rows, static fn(array $row): bool => $row['status'] === 'enabled'),
        ));
    }

    /**
     * 已选模块的活动安装记录数；用于受信安装健康核对，不授予运行权限。
     * 保留数据库集合/计数语义，不把重复输入或重复记录静默判为完整安装。
     * @param list<string> $moduleKeys
     */
    public function activeInstallationCount(array $moduleKeys): int
    {
        if ($moduleKeys === []) {
            return 0;
        }
        return (int) Db::name('module_installation')->where('status', 'active')
            ->whereIn('module_key', $moduleKeys)->count();
    }

    /**
     * Package ownership is the installed fact; module_installation remains as history after retire.
     * Disabled, failed and in-progress packages still block removal of their required dependencies.
     * @param list<string> $moduleKeys
     */
    public function installedPackageModuleCount(array $moduleKeys): int
    {
        if ($moduleKeys === []) {
            return 0;
        }
        return (int) Db::name('plugin_module')->alias('member')
            ->join('plugin_installation plugin', 'plugin.plugin_key=member.plugin_key')
            ->whereIn('member.module_key', $moduleKeys)->where('plugin.status', '<>', 'uninstalled')->count();
    }

    /**
     * 指定租户代码上的启用登记数，沿调用方当前连接读取，不提交其事务。
     * 不附加租户活动状态、生效/到期时间或去重；健康登记不等于当前可用授权。
     * 数据库负责代码比较，缺失存储继续抛错，不返回假成功或配置正文。
     * @param list<string> $moduleKeys
     */
    public function enabledTenantSelectionCount(string $tenantCode, array $moduleKeys): int
    {
        if ($moduleKeys === []) {
            return 0;
        }
        return (int) Db::name('tenant_module')->alias('tm')
            ->join('tenant t', 't.id=tm.tenant_id')->where('t.code', $tenantCode)
            ->where('tm.status', 'enabled')->whereIn('tm.module_key', $moduleKeys)->count();
    }

    /**
     * 固定部署身份投影；宿主保留缺失、停用与清单不匹配的原错误顺序，不由此授予运行权限。
     * @return array{installed_version:mixed,manifest_schema_version:mixed,manifest_digest:mixed,status:mixed}|null
     */
    public function installationIdentity(string $moduleKey, bool $lock = false): ?array
    {
        $query = Db::name('module_installation')->where('module_key', $moduleKey);
        if ($lock) {
            $query->lock(true);
        }
        return $query->field('installed_version,manifest_schema_version,manifest_digest,status')->find();
    }

    /**
     * Read current installation identities in module-key order for a deployment-wide qualification.
     * Missing keys remain absent so the registry can reject them in the caller's original order.
     * @param list<string> $moduleKeys
     * @return array<string,array{installed_version:mixed,manifest_schema_version:mixed,manifest_digest:mixed,status:mixed}>
     */
    public function installationIdentities(array $moduleKeys, bool $lock = false): array
    {
        if ($moduleKeys === []) {
            return [];
        }
        $query = Db::name('module_installation')->whereIn('module_key', $moduleKeys)
            ->field('module_key,installed_version,manifest_schema_version,manifest_digest,status')
            ->order('module_key');
        if ($lock) {
            $query->lock(true);
        }
        $identities = [];
        foreach ($query->select()->toArray() as $row) {
            $identities[(string) $row['module_key']] = $row;
        }
        return $identities;
    }

    /**
     * 固定部署状态投影；只返回生命周期判断所需状态，不公开安装身份、内部查询或可写ORM对象。
     * 缺失记录保持缺失，由宿主按原业务顺序决定拒绝、清理完成或回退到包状态。
     * @param list<string> $moduleKeys
     * @return array<string,array{status:mixed,last_error_code:mixed}>
     */
    public function installationStates(array $moduleKeys): array
    {
        if ($moduleKeys === []) {
            return [];
        }
        $rows = Db::name('module_installation')->whereIn('module_key', $moduleKeys)
            ->field('module_key,status,last_error_code')->order('module_key')->select()->toArray();
        $states = [];
        foreach ($rows as $row) {
            $moduleKey = (string) ($row['module_key'] ?? '');
            if ($moduleKey === '') {
                continue;
            }
            $states[$moduleKey] = [
                'status' => $row['status'] ?? null,
                'last_error_code' => $row['last_error_code'] ?? null,
            ];
        }
        return $states;
    }

    /** 已安装活动目录，不表示当前调用者或某租户获准使用。 @return list<string> */
    public function activeInstallationKeys(): array
    {
        return array_values(array_map('strval', Db::name('module_installation')->where('status', 'active')->order('module_key')->column('module_key')));
    }

    /** 必需基础模块状态需要的固定元数据；不携带配置或内部错误正文。
     * @return list<array{module_key:mixed,revision:mixed,activated_at:mixed,created_at:mixed,updated_at:mixed}>
     */
    public function activeInstallationMetadata(): array
    {
        return Db::name('module_installation')->where('status', 'active')
            ->field('module_key,revision,activated_at,created_at,updated_at')->select()->toArray();
    }

    public function tenantIsActive(int $tenantId): bool
    {
        return $tenantId > 0
            && Db::name('tenant')->where('id', $tenantId)->where('status', 'active')->value('id') !== null;
    }

    /** @return list<array{id:mixed,tenant_id:mixed,module_key:mixed,status:mixed,source:mixed,config_revision:mixed,effective_at:mixed,expires_at:mixed,enabled_at:mixed,disabled_at:mixed,disabled_reason:mixed,created_at:mixed,updated_at:mixed}> */
    public function stateRows(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        return Db::name('tenant_module')->where('tenant_id', $tenantId)
            ->field('id,tenant_id,module_key,status,source,config_revision,effective_at,expires_at,enabled_at,disabled_at,disabled_reason,created_at,updated_at')
            ->order('module_key')->select()->toArray();
    }

    /** 保持数据库时钟的生效包含/到期排除条件，不将代码存在当作租户已开通。 @return list<string> */
    public function activeModuleKeys(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        return array_values(array_map('strval', Db::name('tenant_module')->where('tenant_id', $tenantId)
            ->where('status', 'enabled')
            ->where(fn($query) => $query->whereNull('effective_at')->whereOr('effective_at', '<=', Db::raw('CURRENT_TIMESTAMP(3)')))
            ->where(fn($query) => $query->whereNull('expires_at')->whereOr('expires_at', '>', Db::raw('CURRENT_TIMESTAMP(3)')))
            ->order('module_key')->column('module_key')));
    }

    /**
     * 生命周期阻断按已启用登记，不按当前可用时间或租户状态过滤；不可复用activeModuleKeys缩小阻断。
     * @return array<string,int>
     */
    public function enabledCounts(): array
    {
        $counts = [];
        $rows = Db::name('tenant_module')->where('status', 'enabled')
            ->field('module_key')->fieldRaw('COUNT(*) AS enabled_count')->group('module_key')->select()->toArray();
        foreach ($rows as $row) {
            $counts[(string) $row['module_key']] = (int) $row['enabled_count'];
        }
        return $counts;
    }

    /** @param list<string> $moduleKeys */
    public function hasEnabledModules(array $moduleKeys): bool
    {
        return $moduleKeys !== [] && Db::name('tenant_module')->whereIn('module_key', $moduleKeys)
            ->where('status', 'enabled')->count() !== 0;
    }

    /** 每条启用登记保留一个键，重复值参与现有卸载计划，不返回租户配置或账户信息。 @param list<string> $moduleKeys @return list<string> */
    public function enabledModuleReferences(array $moduleKeys): array
    {
        if ($moduleKeys === []) {
            return [];
        }
        return array_map('strval', Db::name('tenant_module')->whereIn('module_key', $moduleKeys)
            ->where('status', 'enabled')->order('module_key')->column('module_key'));
    }
}
