<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Service;

use PeanutAdmin\Modules\Settings\Infrastructure\BrandDefaults;
use PeanutAdmin\Modules\Settings\Infrastructure\TenantSettingWebsiteStore;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\Settings\Service\TenantSettingService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Settings\Application\WebsiteConfigService as CoreWebsiteConfigService;

/** Public tenant-aware website use cases; persistence and normalization remain module-owned. */
final readonly class WebsiteConfigService
{
    public function __construct(
        private TenantSettingService $settings,
        private FileReferences $files,
    ) {}

    /** @return array<string, string> */
    public function get(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->delegate($context)->get();
    }

    /** @return array<string, string> */
    public function getForEdit(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->delegate($context)->getForEdit();
    }

    /** @param array<string, mixed> $params */
    public function save(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        array $params,
    ): array {
        return $this->delegate($context)->save($params);
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return CoreWebsiteConfigService::fields();
    }

    /** Public template defaults, not stored tenant state or an authorization decision.
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return BrandDefaults::website();
    }

    private function delegate(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
    ): CoreWebsiteConfigService {
        return new CoreWebsiteConfigService(
            new TenantSettingWebsiteStore($context, $this->settings),
            fn(string $value): string => $this->files->getFileUrl($value),
            fn(string $value): string => $this->files->setTenantFileUrl($context, $value),
            self::defaults(),
        );
    }
}
