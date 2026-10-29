<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Service;

use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\OAuth\Infrastructure\WechatOfficialAccountService;

class OfficialAccountMenuApplicationService
{
    public function __construct(
        private readonly ExternalChannelBindings $bindings,
        private readonly WechatOfficialAccountService $officialAccount,
    ) {}

    public function detail(TenantContext $context): array
    {
        $stored = $this->config($context);
        $menu = $stored['menu'] ?? [];
        return ['menu' => is_array($menu) ? $menu : []];
    }

    public function save(TenantContext $context, array $menu): bool
    {
        $this->store($context, $menu);
        return true;
    }

    public function saveAndPublish(
        TenantContext $context,
        array $menu,
    ): bool {
        $config = $this->config($context);
        $this->officialAccount->publishMenu(
            (string) ($config['app_id'] ?? ''),
            (string) ($config['app_secret'] ?? ''),
            $menu,
        );
        $this->store($context, $menu, $config);
        return true;
    }

    private function store(TenantContext $context, array $menu, ?array $config = null): void
    {
        $config ??= $this->config($context);
        $config['menu'] = $menu;
        $this->bindings->update(
            $context,
            ExternalProvider::WECHAT_OFFICIAL_CALLBACK,
            $config,
            (string) ($config['original_id'] ?? $config['app_id'] ?? ''),
        );
    }

    private function config(TenantContext $context): array
    {
        return $this->bindings->config(
            $context,
            ExternalProvider::WECHAT_OFFICIAL_CALLBACK,
        );
    }
}
