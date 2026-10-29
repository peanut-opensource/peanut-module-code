<?php

declare(strict_types=1);

namespace app\platform\services\module;

use app\platform\infrastructure\module\DeployedTenantModuleRegistry;
use app\platform\validation\module\OpisTenantModuleConfigValidator;
use app\common\contract\module\TenantModuleCommands;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Module\ModuleException;
use app\platform\services\PlatformOperatorSessionService;
use app\platform\services\TenantGovernanceService;

/** Permission-gated application closure for instance-local TenantModule governance. */
final readonly class PlatformTenantModuleService implements TenantModuleCommands
{
    private const PERMISSION = 'platform.tenant.module.manage';

    public function __construct(
        private PlatformOperatorSessionService $sessions,
        private TenantGovernanceService $governance,
        private DeployedTenantModuleRegistry $registry,
        private OpisTenantModuleConfigValidator $configValidator,
    ) {}

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function enable(
        string $operatorCredential,
        int $tenantId,
        string $moduleKey,
        array $config,
        string $source,
        ?DateTimeImmutable $effectiveAt,
        ?DateTimeImmutable $expiresAt,
        string $changeReason,
        string $requestId,
    ): array {
        $context = $this->sessions->context($operatorCredential, $requestId);
        $this->sessions->assertAllowed($context, self::PERMISSION);
        try {
            $manifest = $this->registry->requireInstalled($moduleKey);
            $this->configValidator->assertValid($manifest, $config);
        } catch (ModuleException $exception) {
            throw $this->moduleError($exception);
        }

        return $this->governance->enableModule(
            $operatorCredential,
            $tenantId,
            $moduleKey,
            $config,
            $source,
            $effectiveAt,
            $expiresAt,
            $changeReason,
            $requestId,
        );
    }

    /** @return array<string,mixed> */
    public function disable(
        string $operatorCredential,
        int $tenantId,
        string $moduleKey,
        string $changeReason,
        string $requestId,
    ): array {
        $context = $this->sessions->context($operatorCredential, $requestId);
        $this->sessions->assertAllowed($context, self::PERMISSION);
        try {
            $this->registry->requireInstalled($moduleKey);
        } catch (ModuleException $exception) {
            throw $this->moduleError($exception);
        }

        return $this->governance->disableModule(
            $operatorCredential,
            $tenantId,
            $moduleKey,
            $changeReason,
            $requestId,
        );
    }

    private function moduleError(ModuleException $exception): AdminAccessException
    {
        return new AdminAccessException(
            $exception->errorCode,
            $exception->errorCode === 'MODULE_CONFIG_INVALID' ? 422 : 409,
            $exception->getMessage(),
        );
    }
}
