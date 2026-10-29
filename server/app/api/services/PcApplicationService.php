<?php

declare(strict_types=1);

namespace app\api\services;

use app\common\enum\decoration\DecorationEnum;
use app\common\services\decoration\DecorationReadService;
use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** PC 端业务聚合。 */
class PcApplicationService
{
    public function __construct(
        private readonly DecorationReadService $decoration,
        private readonly IndexApplicationService $index,
        private readonly ?PublicArticleQueries $articles = null,
    ) {}

    /** PC 首页文章分组与即时生效的 PC 装修。 */
    public function getIndexData(TenantContext|TenantSystemContext $context): array
    {
        if ($this->articles === null) {
            throw new \DomainException('ARTICLE_MODULE_UNAVAILABLE');
        }
        return [
            'all' => $this->articles->limitArticles('all', 5),
            'new' => $this->articles->limitArticles('new', 7),
            'hot' => $this->articles->limitArticles('hot', 8),
            'decorate' => $this->decoration->pageByType(
                $context,
                DecorationEnum::PC_HOME,
                'article.pc-index',
            ),
        ];
    }

    /** PC 配置聚合；可信 Host 绑定已经在 HTTP 边界解析。 */
    public function config(
        TenantContext|TenantSystemContext $context,
        string $domain,
        string $host,
        ?int $entryTenantId,
    ): array {
        return $this->index->getConfigData($context, $domain, $host, $entryTenantId);
    }

    /** PC 资讯中心公开查询入口。 */
    public function infoCenter(TenantContext|TenantSystemContext $context): array
    {
        $this->assertSystemOperation($context, 'article.info-center');
        return ($this->articles ?? throw new \DomainException('ARTICLE_MODULE_UNAVAILABLE'))->infoCenter();
    }

    /** PC 文章详情公开查询入口。 */
    public function articleDetail(
        TenantContext|TenantSystemContext $context,
        int $memberId,
        int $articleId,
        string $source,
    ): array {
        $this->assertSystemOperation($context, 'article.pc-detail');
        return ($this->articles ?? throw new \DomainException('ARTICLE_MODULE_UNAVAILABLE'))->pcDetail($memberId, $articleId, $source);
    }

    private function assertSystemOperation(
        TenantContext|TenantSystemContext $context,
        string $operation,
    ): void {
        if ($context instanceof TenantSystemContext && !hash_equals($operation, $context->operation)) {
            throw new \DomainException('EXECUTION_PUBLIC_TENANT_CONTEXT_REQUIRED');
        }
    }
}
