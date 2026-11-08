<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Service;

use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\Settings\Service\TenantSettingService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** Typed access to Tenant-owned public application settings. */
final class TenantApplicationSettingService implements TenantApplicationSettings
{
    private const AGREEMENT = 'agreement';
    private const STATISTICS = 'site-statistics';
    private const MEMBER_PROFILE = 'member-profile';
    private const LOGIN = 'login';
    private const WEB_PAGE = 'web-page';
    private const HOT_SEARCH = 'hot-search';

    public function __construct(private readonly TenantSettingService $settings) {}

    public function agreement(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->document($context, self::AGREEMENT, [
            'service_title' => '',
            'service_content' => '',
            'privacy_title' => '',
            'privacy_content' => '',
        ]);
    }

    public function replaceAgreement(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void
    {
        $this->settings->replace($context, self::AGREEMENT, $document);
    }

    public function statistics(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->document($context, self::STATISTICS, ['clarity_code' => '']);
    }

    public function replaceStatistics(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void
    {
        $this->settings->replace($context, self::STATISTICS, $document);
    }

    public function memberProfile(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->document($context, self::MEMBER_PROFILE, ['user_avatar' => '']);
    }

    public function replaceMemberProfile(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void
    {
        $this->settings->replace($context, self::MEMBER_PROFILE, $document);
    }

    public function login(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        $document = $this->document($context, self::LOGIN, [
            'login_way' => [1, 2],
            'coerce_mobile' => 0,
            'login_agreement' => 0,
            'third_auth' => 0,
            'wechat_auth' => 0,
        ]);
        $loginWay = is_array($document['login_way'] ?? null)
            ? array_values(array_unique(array_map('intval', $document['login_way'])))
            : [1, 2];
        $document['login_way'] = array_values(array_intersect($loginWay, [1, 2]));
        return $document;
    }

    public function replaceLogin(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void
    {
        $this->settings->replace($context, self::LOGIN, $document);
    }

    public function webPage(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->document($context, self::WEB_PAGE, [
            'status' => 1,
            'page_status' => 0,
            'page_url' => '',
        ]);
    }

    public function replaceWebPage(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void
    {
        $this->settings->replace($context, self::WEB_PAGE, $document);
    }

    public function hotSearch(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->document($context, self::HOT_SEARCH, ['status' => 0]);
    }

    public function replaceHotSearch(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void
    {
        $this->settings->replace($context, self::HOT_SEARCH, $document);
    }

    public function copyright(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->document($context, 'copyright', ['config' => []]);
    }

    public function replaceCopyright(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, array $document): void
    {
        $this->settings->replace($context, 'copyright', $document);
    }

    private function document(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $namespace,
        array $defaults,
    ): array {
        return array_replace(
            $defaults,
            $this->settings->get($context, $namespace)->document,
        );
    }
}
