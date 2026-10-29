<?php

declare(strict_types=1);

namespace app\api\services;

use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use app\common\services\RichTextResourceService;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use app\common\enum\decoration\DecorationEnum;
use app\common\services\decoration\DecorationReadService;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PeanutAdmin\Modules\Identity\Contract\TenantIdentityQuery;

class IndexApplicationService
{
    public function __construct(
        private readonly TenantIdentityQuery $tenantIdentities,
        private readonly TenantApplicationSettings $applicationSettings,
        private readonly RichTextResourceService $richText,
        private readonly DecorationReadService $decoration,
        private readonly WebsiteConfigService $website,
        private readonly string $projectVersion,
        private readonly array $demoLoginConfig,
        private readonly ?PublicArticleQueries $articles = null,
    ) {}

    /** 全局配置（uniapp / H5 用） */
    public function getConfigData(
        TenantContext|TenantSystemContext $context,
        string $domain,
        string $host,
        ?int $entryTenantId,
    ): array {
        $website = $this->website->get($context);
        $login = $this->applicationSettings->login($context);
        $statistics = $this->applicationSettings->statistics($context);
        $webPageSetting = $this->applicationSettings->webPage($context);
        $webPage   = [
            'status'      => (int) $webPageSetting['status'],
            'page_status' => (int) $webPageSetting['page_status'],
            'page_url'    => (string) $webPageSetting['page_url'],
            'url'         => rtrim($domain, '/') . '/mobile',
        ];

        return [
            'domain'   => $domain,
            'website'  => $website,
            'tenantName' => $this->entryTenantName($entryTenantId),
            'demo'     => $this->demoLogin($host),
            'login'    => [
                'login_way' => $login['login_way'],
                'coerce_mobile' => (int) $login['coerce_mobile'],
                'login_agreement' => (int) $login['login_agreement'],
                'third_auth' => (int) $login['third_auth'],
                'wechat_auth' => (int) $login['wechat_auth'],
            ],
            'copyright' => $this->copyright($context),
            'site_statistics' => [
                'clarity_code' => (string) $statistics['clarity_code'],
            ],
            'web_page' => $webPage,
            'tabbar'   => $this->decoration->tabbar(
                $context,
                true,
                'decoration.config',
            ),
            'theme'    => $this->decoration->pageByType(
                $context,
                DecorationEnum::SYSTEM_THEME,
                'decoration.config',
            ),
            'version'  => $this->projectVersion,
        ];
    }

    /** @return array{enabled:bool,email:string,password:string} */
    private function demoLogin(string $host): array
    {
        return DemoAccountPolicy::publicLoginConfiguration($host, $this->demoLoginConfig);
    }

    private function entryTenantName(?int $tenantId): string
    {
        try {
            if ($tenantId === null) {
                return '';
            }
            return $this->tenantIdentities->activeName($tenantId);
        } catch (\Throwable) {
            return '';
        }
    }

    private function copyright(TenantContext|TenantSystemContext $context): array
    {
        $document = $this->applicationSettings->copyright($context);
        return is_array($document['config'] ?? null) ? $document['config'] : [];
    }

    /** 政策协议（type: privacy | service） */
    public function getPolicyByType(
        TenantContext|TenantSystemContext $context,
        string $type,
    ): array {
        $setting = $this->applicationSettings->agreement($context);
        $prefix = $type === 'privacy' ? 'privacy' : 'service';
        return [
            'title'   => (string) $setting[$prefix . '_title'],
            'content' => $this->richText->forRead(
                (string) $setting[$prefix . '_content'],
            ),
        ];
    }

    /** 首页数据 */
    public function getIndexData(TenantContext|TenantSystemContext $context): array
    {
        return [
            'article' => ($this->articles ?? throw new \DomainException('ARTICLE_MODULE_UNAVAILABLE'))->homeArticles(20),
            'decorate' => $this->decoration->pageByType(
                $context,
                DecorationEnum::MOBILE_HOME,
                'article.index',
            ),
        ];
    }
}
