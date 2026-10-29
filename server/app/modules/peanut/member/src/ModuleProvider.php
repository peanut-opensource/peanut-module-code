<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member;

use PeanutAdmin\Modules\Member\Service\MemberAdministrationService;
use PeanutAdmin\Modules\Member\Service\MemberBalanceContractService;
use PeanutAdmin\Modules\Member\Service\MemberIdentityContractService;
use PeanutAdmin\Modules\Member\Service\MemberQueryService;
use PeanutAdmin\Modules\Member\Service\MemberProfileContractService;
use PeanutAdmin\Modules\Member\Service\MemberSessionService;
use PeanutAdmin\Modules\Member\Service\MemberTagContractService;
use PeanutAdmin\Modules\Member\Contract\MemberBalanceCommands;
use PeanutAdmin\Modules\Member\Contract\MemberAdministration;
use PeanutAdmin\Modules\Member\Contract\MemberIdentityCommands;
use PeanutAdmin\Modules\Member\Contract\MemberProfileCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use PeanutAdmin\Modules\Member\Contract\MemberTagCommands;
use PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup;
use PeanutAdmin\Modules\Member\Contract\MemberSessionStore;
use PeanutAdmin\Modules\Member\Contract\MemberSessions;
use PeanutAdmin\Modules\Member\Infrastructure\Persistence\ThinkPhpMemberSessionStore;
use PeanutAdmin\Modules\Member\Infrastructure\Persistence\ThinkPhpMemberSubjectLookup;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.member';
    }

    public function bindings(): array
    {
        return [
            \app\api\services\UserTokenService::class => static fn(\think\App $app) => new \app\api\services\UserTokenService(
                (string) $app->config->get('jwt.secret', ''),
                (int) $app->config->get('jwt.expire', 0),
                $app->make(MemberSessions::class),
            ),
            \app\api\services\LoginApplicationService::class => static fn(\think\App $app) => new \app\api\services\LoginApplicationService(
                $app->make(MemberIdentityCommands::class),
                $app->make(\PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings::class),
                $app->make(\PeanutAdmin\Modules\File\Contract\FileReferences::class),
                $app->make(\app\api\services\UserTokenService::class),
                (string) $app->config->get('project.default_image.user_avatar', ''),
                $app->bound(\PeanutAdmin\Modules\Notification\Contract\VerificationCodeCommands::class)
                    ? $app->make(\PeanutAdmin\Modules\Notification\Contract\VerificationCodeCommands::class) : null,
            ),
            MemberQueries::class => MemberQueryService::class,
            MemberSubjectLookup::class => ThinkPhpMemberSubjectLookup::class,
            MemberSessionStore::class => ThinkPhpMemberSessionStore::class,
            MemberSessions::class => MemberSessionService::class,
            MemberIdentityCommands::class => MemberIdentityContractService::class,
            MemberProfileCommands::class => MemberProfileContractService::class,
            MemberTagCommands::class => MemberTagContractService::class,
            MemberBalanceCommands::class => MemberBalanceContractService::class,
            MemberAdministration::class => MemberAdministrationService::class,
        ];
    }
}
