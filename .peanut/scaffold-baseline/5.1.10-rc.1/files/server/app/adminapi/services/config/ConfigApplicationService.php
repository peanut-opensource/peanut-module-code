<?php

declare(strict_types=1);

namespace app\adminapi\services\config;

use PeanutAdmin\Modules\File\Contract\FileReferences;
use app\common\services\RichTextResourceService;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;

class ConfigApplicationService
{
    public function __construct(
        private readonly TenantApplicationSettings $applicationSettings,
        private readonly FileReferences $files,
        private readonly RichTextResourceService $richText,
        private readonly WebsiteConfigService $website,
        private readonly string $defaultAvatar,
    ) {}

    public function getWebsite(AuthenticatedMemberContext|TenantContext $context): array
    {
        return $this->website->getForEdit($context);
    }

    public function saveWebsite(AuthenticatedMemberContext|TenantContext $context, array $params): array
    {
        return $this->website->save($context, $params);
    }

    public function getCopyright(AuthenticatedMemberContext|TenantContext $context): array
    {
        $value = $this->applicationSettings->copyright($context)['config'] ?? [];
        if (is_array($value)) {
            return $value;
        }
        return [];
    }

    public function saveCopyright(AuthenticatedMemberContext|TenantContext $context, array $params): bool
    {
        $this->applicationSettings->replaceCopyright($context, ['config' => $params['config'] ?? []]);
        return true;
    }

    public function getAgreement(AuthenticatedMemberContext|TenantContext $context): array
    {
        $setting = $this->applicationSettings->agreement($context);
        return [
            'service_title' => (string) $setting['service_title'],
            'service_content' => $this->richText->forRead((string) $setting['service_content']),
            'privacy_title' => (string) $setting['privacy_title'],
            'privacy_content' => $this->richText->forRead((string) $setting['privacy_content']),
        ];
    }

    public function saveAgreement(
        AuthenticatedMemberContext|TenantContext $context,
        array $params,
    ): bool {
        $this->applicationSettings->replaceAgreement($context, [
            'service_title' => trim((string) $params['service_title']),
            'service_content' => $this->richText->forStorage(
                (string) $params['service_content'],
                $context,
            ),
            'privacy_title' => trim((string) $params['privacy_title']),
            'privacy_content' => $this->richText->forStorage(
                (string) $params['privacy_content'],
                $context,
            ),
        ]);
        return true;
    }

    public function getStatistics(AuthenticatedMemberContext|TenantContext $context): array
    {
        $setting = $this->applicationSettings->statistics($context);
        return ['clarity_code' => (string) $setting['clarity_code']];
    }

    public function saveStatistics(
        AuthenticatedMemberContext|TenantContext $context,
        array $params,
    ): bool {
        $this->applicationSettings->replaceStatistics($context, [
            'clarity_code' => trim((string) $params['clarity_code']),
        ]);
        return true;
    }

    public function getUser(AuthenticatedMemberContext|TenantContext $context): array
    {
        $setting = $this->applicationSettings->memberProfile($context);
        $avatar = trim((string) $setting['user_avatar']);
        $displayAvatar = $avatar !== '' ? $avatar : $this->defaultAvatar;
        return [
            'default_avatar' => $avatar,
            'default_avatar_url' => $displayAvatar !== '' && $displayAvatar === $this->defaultAvatar
                ? '/' . ltrim($this->defaultAvatar, '/')
                : $this->files->getFileUrl($displayAvatar),
        ];
    }

    public function saveUser(
        AuthenticatedMemberContext|TenantContext $context,
        array $params,
    ): bool {
        $avatar = (string) $params['default_avatar'];
        $this->applicationSettings->replaceMemberProfile($context, [
            'user_avatar' => $avatar !== '' && $avatar === $this->defaultAvatar
                ? $avatar
                : $this->files->setTenantFileUrl($context, $avatar),
        ]);
        return true;
    }

    public function getLogin(AuthenticatedMemberContext|TenantContext $context): array
    {
        $setting = $this->applicationSettings->login($context);
        return [
            'login_way' => $setting['login_way'],
            'coerce_mobile' => (int) $setting['coerce_mobile'],
            'login_agreement' => (int) $setting['login_agreement'],
            'third_auth' => (int) $setting['third_auth'],
            'wechat_auth' => (int) $setting['wechat_auth'],
        ];
    }

    public function saveLogin(
        AuthenticatedMemberContext|TenantContext $context,
        array $params,
    ): bool {
        $loginWay = array_values(array_unique(array_map('intval', $params['login_way'])));
        sort($loginWay);
        $this->applicationSettings->replaceLogin($context, [
            'login_way' => $loginWay,
            'coerce_mobile' => (int) $params['coerce_mobile'],
            'login_agreement' => (int) $params['login_agreement'],
            'third_auth' => (int) $params['third_auth'],
            'wechat_auth' => (int) $params['wechat_auth'],
        ]);
        return true;
    }
}
