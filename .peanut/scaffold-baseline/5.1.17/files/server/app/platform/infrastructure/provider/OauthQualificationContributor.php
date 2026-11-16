<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

final class OauthQualificationContributor extends AbstractTenantBindingQualificationContributor
{
    protected function definitions(): array
    {
        return array_map(static fn(string $provider): array => [
            'provider_key' => $provider,
            'binding_provider' => $provider,
            'category' => 'oauth',
            'callback_required' => true,
        ], [
            'wechat.official-account',
            'oauth.wechat.oa',
            'oauth.wechat.mini-program',
            'oauth.wechat.open-pc',
        ]);
    }
}
