<?php

declare(strict_types=1);

namespace app\platform\query;

use app\common\contract\module\ModuleQualification;
use app\common\contract\module\ModuleQualificationQuery;
use app\platform\context\PlatformOperatorContext;
use app\platform\services\PlatformOperatorSessionService;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Contract\PlatformDirectoryQueries;

/** Adapts the established platform session to owner queries; module composition remains application-owned. */
final readonly class PlatformControlPlaneQueryService
{
    public function __construct(
        private PlatformOperatorSessionService $sessions,
        private ModuleQualificationQuery $qualification,
        private PlatformDirectoryQueries $directory,
    ) {}

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function operators(PlatformOperatorContext $context, PageRequest $page): array
    {
        return $this->directory->operators($context->core, $page);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function roles(PlatformOperatorContext $context, PageRequest $page): array
    {
        return $this->directory->roles($context->core, $page);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function permissions(PlatformOperatorContext $context, PageRequest $page): array
    {
        return $this->directory->permissions($context->core, $page);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function audit(PlatformOperatorContext $context, PageRequest $page): array
    {
        return $this->directory->audit($context->core, $page);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function moduleStates(PlatformOperatorContext $context, int $tenantId, PageRequest $page): array
    {
        $this->sessions->assertAllowed($context, 'platform.tenant.read');
        $states = [];
        foreach ($this->qualification->tenantModuleStates($tenantId) as $state) {
            $states[$state->moduleKey] = $state->toArray();
        }
        $rows = array_map(static function (ModuleQualification $module) use ($states, $tenantId): array {
            $state = $states[$module->moduleKey] ?? null;
            return [
                'id' => $state['id'] ?? null, 'tenant_id' => $tenantId, 'module_key' => $module->moduleKey,
                'status' => $state['status'] ?? 'not_enabled', 'source' => $state['source'] ?? 'not_configured',
                'config_revision' => $state['config_revision'] ?? 0, 'effective_at' => $state['effective_at'] ?? null,
                'expires_at' => $state['expires_at'] ?? null, 'enabled_at' => $state['enabled_at'] ?? null,
                'disabled_at' => $state['disabled_at'] ?? null, 'disabled_reason' => $state['disabled_reason'] ?? null,
                'created_at' => $state['created_at'] ?? null, 'updated_at' => $state['updated_at'] ?? null,
                'installed_version' => $module->version, 'installation_status' => $module->status,
            ];
        }, $this->qualification->installedModules());
        return ['items' => array_slice($rows, $page->offset(), $page->pageSize), 'total' => count($rows)];
    }

    /** @return array<string,mixed> */
    public function owner(PlatformOperatorContext $context, int $tenantId): array
    {
        return $this->directory->owner($context->core, $tenantId);
    }
}
