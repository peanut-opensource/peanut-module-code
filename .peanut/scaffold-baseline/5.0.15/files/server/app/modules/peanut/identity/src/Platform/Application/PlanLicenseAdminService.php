<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Identity\Module\ModuleAvailabilityService;
use think\db\Raw;
use think\facade\Db;

/** Instance-local shared plans and one current license per tenant. */
final readonly class PlanLicenseAdminService
{
    public function __construct(private CompiledModuleRegistry $registry, private TenantEntitlementService $entitlements, private AuditService $audit) {}

    public function plans(int $page, int $pageSize): array
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw AdminAccessException::invalid('PLAN_PAGE_INVALID', 'Invalid pagination.');
        }
        $rows = Db::name('plan')->order('id')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();
        return ['lists' => array_map(fn(array $row): array => $this->plan((int) $row['id']), $rows), 'count' => (int) Db::name('plan')->count()];
    }

    public function savePlan(PlatformContext $actor, array $input, bool $create): array
    {
        $this->fields($input, $create ? ['code','name','module_keys','permission_keys','change_reason'] : ['id','expected_revision','name','status','module_keys','permission_keys','change_reason']);
        $name = $this->text($input['name'] ?? null, 160);
        $reason = $this->text($input['change_reason'] ?? null, 500);
        $code = $create ? $this->text($input['code'] ?? null, 64) : null;
        if ($create && preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $code) !== 1) {
            throw AdminAccessException::invalid('PLAN_CODE_INVALID', 'Invalid plan code.');
        }
        $status = $create ? 'active' : ($input['status'] ?? null);
        if (!in_array($status, ['active','inactive'], true)) {
            throw AdminAccessException::invalid('PLAN_STATUS_INVALID', 'Invalid plan status.');
        }
        $modules = $this->keys($input['module_keys'] ?? null);
        $permissions = $this->keys($input['permission_keys'] ?? null);
        $id = $create ? 0 : $this->integer($input['id'] ?? null);
        $expected = $create ? 0 : $this->integer($input['expected_revision'] ?? null);
        return Db::transaction(function () use ($actor, $name, $reason, $code, $status, $modules, $permissions, $id, $expected, $create): array {
            $before = null;
            $now = $this->now();
            if (!$create) {
                $current = Db::name('plan')->where('id', $id)->lock(true)->find();
                if ($current === null) { throw AdminAccessException::notFound(); }
                if ((int) $current['revision'] !== $expected) { throw AdminAccessException::revisionMismatch(); }
                $before = $this->plan($id);
            } elseif (Db::name('plan')->where('code', $code)->lock(true)->find() !== null) {
                throw AdminAccessException::conflict('PLAN_CODE_CONFLICT', 'The plan code is already in use.');
            }
            $this->assertSelection($modules, $permissions);
            if ($create) {
                $id = (int) Db::name('plan')->insertGetId(['code' => $code,'name' => $name,'status' => $status,'revision' => 1,'created_at' => $now,'updated_at' => $now]);
            } else {
                Db::name('plan')->where('id', $id)->update(['name' => $name,'status' => $status,'revision' => new Raw('revision + 1'),'updated_at' => $now]);
            }
            $this->replaceDetails($id, $modules, $permissions);
            $after = $this->plan($id);
            $this->audit->platform($actor->operatorId,$actor->accountId,$actor->requestId,'plan.saved','platform.plan.manage',
                ['change_reason' => $reason], 'plan', (string) $id, $before, $after);
            return $after;
        });
    }

    public function tenantLicense(int $tenantId): array
    {
        if ($tenantId < 1 || Db::name('tenant')->where('id', $tenantId)->find() === null) { throw AdminAccessException::notFound(); }
        return ['tenant_id' => $tenantId, 'license' => $this->entitlements->license($tenantId)];
    }

    public function saveLicense(PlatformContext $actor, array $input): array
    {
        $this->fields($input, ['tenant_id','plan_id','status','effective_at','expires_at','expected_revision','change_reason']);
        $tenantId = $this->integer($input['tenant_id'] ?? null);
        $planId = $this->integer($input['plan_id'] ?? null);
        $expected = $this->integer($input['expected_revision'] ?? null, 0);
        $status = $input['status'] ?? null;
        $reason = $this->text($input['change_reason'] ?? null, 500);
        if (!in_array($status, ['active','suspended'], true) || !array_key_exists('expires_at', $input)) {
            throw AdminAccessException::invalid('LICENSE_INPUT_INVALID', 'A valid status and expiry are required.');
        }
        $effective = $this->date($input['effective_at'] ?? null);
        $expires = $input['expires_at'] === null ? null : $this->date($input['expires_at']);
        if ($expires !== null && $expires <= $effective) { throw AdminAccessException::invalid('LICENSE_DATES_INVALID', 'Expiry must follow the effective time.'); }
        return Db::transaction(function () use ($actor,$tenantId,$planId,$expected,$status,$reason,$effective,$expires): array {
            $plan = Db::name('plan')->where('id', $planId)->lock(true)->find();
            if ($plan === null) { throw AdminAccessException::notFound(); }
            if (Db::name('tenant')->where('id', $tenantId)->lock(true)->find() === null) { throw AdminAccessException::notFound(); }
            $current = Db::name('tenant_license')->where('tenant_id', $tenantId)->lock(true)->find();
            if ((int) ($current['revision'] ?? 0) !== $expected) { throw AdminAccessException::revisionMismatch(); }
            if ($plan['status'] !== 'active' && ($current === null || (int) $current['plan_id'] !== $planId)) {
                throw AdminAccessException::conflict('PLAN_INACTIVE', 'The plan is closed to new assignments.');
            }
            $modules = Db::name('plan_module')->where('plan_id', $planId)->column('module_key');
            $permissions = Db::name('plan_permission')->where('plan_id', $planId)->column('permission_key');
            $this->assertSelection($modules, $permissions);
            $before = $this->entitlements->license($tenantId);
            $now = $this->now();
            $values = ['plan_id' => $planId,'status' => $status,'effective_at' => $effective,'expires_at' => $expires,'revision' => $expected + 1,'updated_at' => $now];
            if ($current === null) { Db::name('tenant_license')->insert($values + ['tenant_id' => $tenantId,'created_at' => $now]); }
            else { Db::name('tenant_license')->where('tenant_id', $tenantId)->update($values); }
            Db::name('tenant')->where('id', $tenantId)->update(['authorization_revision' => new Raw('authorization_revision + 1'),'updated_at' => $now]);
            $after = $this->entitlements->license($tenantId);
            $this->audit->platform($actor->operatorId,$actor->accountId,$actor->requestId,'tenant.license.saved','platform.tenant.license.manage',
                ['tenant_id' => (string) $tenantId,'change_reason' => $reason], 'tenant-license', (string) $after['id'], $before, $after);
            return ['tenant_id' => $tenantId,'license' => $after];
        });
    }

    /** Trusted fresh-install composition only. No role grant or subsequent module-install adoption. */
    public function initializeInstallation(array $moduleKeys, string $tenantCode): array
    {
        $tenantId = (int) Db::name('tenant')->where('code', $tenantCode)->value('id');
        if ($tenantId < 1) { throw new \RuntimeException('INSTALL_TENANT_MISSING'); }
        return $this->initializeTenant($tenantId, $moduleKeys);
    }

    public function initializeTenant(int $tenantId, array $moduleKeys): array
    {
        return Db::transaction(function () use ($tenantId, $moduleKeys): array {
            $modules = array_values(array_filter($moduleKeys, fn(string $key): bool => !$this->registry->isRequiredTenantFoundation($key)));
            $permissions = Db::name('permission')->where('status','active')->whereIn('module_key',$moduleKeys)->whereNotLike('key','platform.%')->order('key')->column('key');
            $this->assertSelection($modules, $permissions);
            $plan = Db::name('plan')->where('code','application-default')->lock(true)->find();
            $now = $this->now();
            if ($plan === null) {
                $planId = (int) Db::name('plan')->insertGetId(['code'=>'application-default','name'=>'Application default','status'=>'active','revision'=>1,'created_at'=>$now,'updated_at'=>$now]);
                $this->replaceDetails($planId,$modules,$permissions);
            } else { $planId = (int) $plan['id']; }
            if (Db::name('tenant_license')->where('tenant_id',$tenantId)->lock(true)->find() === null) {
                Db::name('tenant_license')->insert(['tenant_id'=>$tenantId,'plan_id'=>$planId,'status'=>'active','effective_at'=>$now,'expires_at'=>null,'revision'=>1,'created_at'=>$now,'updated_at'=>$now]);
            }
            return $this->tenantLicense($tenantId);
        });
    }

    private function assertSelection(array $modules, array $permissions): void
    {
        foreach ($modules as $key) {
            $manifest = $this->registry->requireManifest($key);
            if (($manifest->data['tenant']['enableable'] ?? true) !== true || $this->registry->isRequiredTenantFoundation($key)) {
                throw AdminAccessException::invalid('PLAN_MODULE_INVALID','Only optional tenant modules belong to a plan.');
            }
            foreach ([...($manifest->data['dependencies'] ?? []), ...($manifest->data['tenant']['requires'] ?? [])] as $dependency) {
                $dependency = is_array($dependency) ? ($dependency['module_key'] ?? '') : $dependency;
                if (is_string($dependency) && $dependency !== '' && !$this->registry->isRequiredTenantFoundation($dependency) && !in_array($dependency,$modules,true)) {
                    throw AdminAccessException::invalid('PLAN_MODULE_DEPENDENCY_REQUIRED','Include every optional tenant module dependency.');
                }
            }
            app(ModuleAvailabilityService::class)->assertDeployment($key, true);
        }
        $rows = Db::name('permission')->whereIn('key',$permissions)->where('status','active')->whereNotLike('key','platform.%')->select()->toArray();
        if (count($rows) !== count($permissions)) { throw AdminAccessException::invalid('PLAN_PERMISSION_INVALID','Select active tenant permissions.'); }
        $requiredOwners = [];
        foreach ($rows as $permission) {
            $owner = $permission['module_key'];
            if (in_array($owner, ['core','peanut.admin','instance.tenant'], true) || in_array($owner,$modules,true)) {
                continue;
            }
            if (!$this->registry->isRequiredTenantFoundation($owner)) {
                throw AdminAccessException::invalid('PLAN_PERMISSION_MODULE_REQUIRED','Include the permission owner module.');
            }
            $requiredOwners[$owner] = true;
        }
        $apiRows = Db::name('permission_api')->alias('binding')->join('permission permission','permission.id=binding.permission_id')
            ->join('api_endpoint api','api.id=binding.api_id')->whereIn('permission.key',$permissions)->where('api.status','active')
            ->field('api.module_key,api.audience,api.prerequisite_permission_keys')->select()->toArray();
        foreach ($apiRows as $api) {
            if ($api['audience'] !== 'tenant' || (!in_array($api['module_key'], ['core','peanut.admin'], true)
                && !$this->registry->isRequiredTenantFoundation($api['module_key']) && !in_array($api['module_key'],$modules,true))) {
                throw AdminAccessException::invalid('PLAN_API_MODULE_REQUIRED','The bound API must belong to an included tenant module.');
            }
            if (array_diff(json_decode($api['prerequisite_permission_keys'],true,32,JSON_THROW_ON_ERROR), [...$permissions,...CorePermissionCatalog::TENANT]) !== []) {
                throw AdminAccessException::invalid('PLAN_PREREQUISITE_REQUIRED','Include all API prerequisite permissions.');
            }
            if (!in_array($api['module_key'], ['core','peanut.admin'], true)
                && !in_array($api['module_key'], $modules, true)) {
                $requiredOwners[$api['module_key']] = true;
            }
        }
        foreach (array_keys($requiredOwners) as $owner) {
            app(ModuleAvailabilityService::class)->assertDeployment($owner, true);
        }
    }

    private function plan(int $id): array
    {
        $row = Db::name('plan')->where('id',$id)->find() ?? throw AdminAccessException::notFound();
        return ['id'=>(int)$row['id'],'code'=>$row['code'],'name'=>$row['name'],'status'=>$row['status'],'revision'=>(int)$row['revision'],
            'module_keys'=>Db::name('plan_module')->where('plan_id',$id)->order('module_key')->column('module_key'),
            'permission_keys'=>Db::name('plan_permission')->where('plan_id',$id)->order('permission_key')->column('permission_key')];
    }

    private function replaceDetails(int $id,array $modules,array $permissions): void
    {
        Db::name('plan_module')->where('plan_id',$id)->delete();
        Db::name('plan_permission')->where('plan_id',$id)->delete();
        foreach ($modules as $key) { Db::name('plan_module')->insert(['plan_id'=>$id,'module_key'=>$key]); }
        foreach ($permissions as $key) { Db::name('plan_permission')->insert(['plan_id'=>$id,'permission_key'=>$key]); }
    }

    private function fields(array $input,array $allowed): void
    {
        if (array_diff(array_keys($input),$allowed) !== []) { throw AdminAccessException::invalid('PLAN_INPUT_INVALID','Unknown input fields.'); }
    }
    private function integer(mixed $value,int $minimum=1): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value,FILTER_VALIDATE_INT) === false || (int)$value < $minimum) { throw AdminAccessException::invalid('PLAN_INPUT_INVALID','An integer identity or revision is required.'); }
        return (int)$value;
    }
    private function text(mixed $value,int $maximum): string
    {
        if (!is_string($value) || trim($value)==='' || mb_strlen(trim($value))>$maximum) { throw AdminAccessException::invalid('PLAN_INPUT_INVALID','A valid text value is required.'); }
        return trim($value);
    }
    private function keys(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value)>2000) { throw AdminAccessException::invalid('PLAN_INPUT_INVALID','A list of explicit keys is required.'); }
        foreach ($value as $key) { if (!is_string($key) || $key==='' || strlen($key)>160 || trim($key)!==$key) { throw AdminAccessException::invalid('PLAN_INPUT_INVALID','Invalid selection key.'); } }
        $keys=array_values(array_unique($value)); sort($keys,SORT_STRING); return $keys;
    }
    private function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/D',$value)!==1) { throw AdminAccessException::invalid('LICENSE_DATES_INVALID','A UTC timestamp ending in Z is required.'); }
        try { $date=new DateTimeImmutable($value); } catch (\Exception) { throw AdminAccessException::invalid('LICENSE_DATES_INVALID','Invalid timestamp.'); }
        if (DateTimeImmutable::getLastErrors() !== false) { throw AdminAccessException::invalid('LICENSE_DATES_INVALID','Invalid timestamp.'); }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
    private function now(): string { return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'); }
}
