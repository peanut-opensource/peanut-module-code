<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth;

use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\OAuth\Service\OAuthQueryService;
use PeanutAdmin\Modules\OAuth\Service\OAuthCommandService;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCallbackLocator;
use PeanutAdmin\Modules\OAuth\Contract\OfficialAccountCallbacks;
use PeanutAdmin\Modules\OAuth\Contract\OAuthCommands;
use PeanutAdmin\Modules\OAuth\Contract\OAuthPersistence;
use PeanutAdmin\Modules\OAuth\Contract\OAuthQueries;
use PeanutAdmin\Modules\OAuth\Infrastructure\Persistence\ThinkPhpOAuthCallbackLocator;
use PeanutAdmin\Modules\OAuth\Infrastructure\Persistence\ThinkPhpOAuthPersistence;
use app\common\infrastructure\oauth\WechatOAuthTransport;
use PeanutAdmin\IntegrationSecurity\OAuth\OAuthTransport;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.oauth';
    }

    public function queries(): OAuthQueries
    {
        return new OAuthQueryService(new ThinkPhpOAuthPersistence());
    }

    public function bindings(): array
    {
        return [
            OAuthCallbackLocator::class => ThinkPhpOAuthCallbackLocator::class,
            OAuthPersistence::class => ThinkPhpOAuthPersistence::class,
            OAuthTransport::class => WechatOAuthTransport::class,
            OfficialAccountCallbacks::class => \PeanutAdmin\Modules\OAuth\Service\OfficialAccountApplicationService::class,
            OAuthCommands::class => fn(App $app): OAuthCommands => new OAuthCommandService(
                $app->make(\PeanutAdmin\Modules\Member\Contract\MemberQueries::class),
                $app->make(\PeanutAdmin\Modules\Member\Contract\MemberIdentityCommands::class),
                $app->make(\PeanutAdmin\Modules\Member\Contract\MemberProfileCommands::class),
                $app->make(\PeanutAdmin\Modules\Notification\Contract\VerificationCodeCommands::class),
                $app->make(\app\common\persistence\AdvisoryLockExecution::class),
                $app->make(\PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings::class),
                $app->make(ExternalTenantResolutionService::class),
                $app->make(OAuthPersistence::class),
                $app->make(OAuthTransport::class),
                (string) $app->config->get('project.default_image.user_avatar', ''),
            ),
            OAuthQueries::class => OAuthQueryService::class,
        ];
    }
}
