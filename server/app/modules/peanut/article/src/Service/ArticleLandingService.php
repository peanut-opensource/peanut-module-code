<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use app\common\enum\decoration\DecorationEnum;
use app\common\services\decoration\DecorationReadService;
use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final readonly class ArticleLandingService
{
    public function __construct(
        private DecorationReadService $decoration,
        private PublicArticleQueries $articles,
    ) {}

    public function mobileIndex(TenantContext|TenantSystemContext $context): array
    {
        return [
            'article' => $this->articles->homeArticles(20),
            'decorate' => $this->decoration->pageByType($context, DecorationEnum::MOBILE_HOME, 'article.index'),
        ];
    }

    public function pcIndex(TenantContext|TenantSystemContext $context): array
    {
        return [
            'all' => $this->articles->limitArticles('all', 5),
            'new' => $this->articles->limitArticles('new', 7),
            'hot' => $this->articles->limitArticles('hot', 8),
            'decorate' => $this->decoration->pageByType($context, DecorationEnum::PC_HOME, 'article.pc-index'),
        ];
    }

    public function infoCenter(TenantContext|TenantSystemContext $context): array
    {
        $this->assertSystemOperation($context, 'article.info-center');
        return $this->articles->infoCenter();
    }

    public function pcDetail(TenantContext|TenantSystemContext $context, int $memberId, int $articleId, string $source): array
    {
        $this->assertSystemOperation($context, 'article.pc-detail');
        return $this->articles->pcDetail($memberId, $articleId, $source);
    }

    private function assertSystemOperation(TenantContext|TenantSystemContext $context, string $operation): void
    {
        if ($context instanceof TenantSystemContext && !hash_equals($operation, $context->operation)) {
            throw new \DomainException('EXECUTION_PUBLIC_TENANT_CONTEXT_REQUIRED');
        }
    }
}
