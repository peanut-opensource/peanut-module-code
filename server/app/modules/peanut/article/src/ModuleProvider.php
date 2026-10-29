<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article;

use PeanutAdmin\Modules\Article\Service\ArticleAdministrationService;
use PeanutAdmin\Modules\Article\Service\ArticleCategoryAdministrationService;
use PeanutAdmin\Modules\Article\Service\ArticleQueryService;
use PeanutAdmin\Modules\Article\Service\PublicArticleService;
use PeanutAdmin\Modules\Article\Contract\ArticleAdministration;
use PeanutAdmin\Modules\Article\Contract\ArticleCategoryAdministration;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;
use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerContributor
{
    public function moduleKey(): string
    {
        return 'peanut.article';
    }

    public function documentOwnerPolicies(): array
    {
        return ['article' => \PeanutAdmin\Modules\Article\Service\ArticleDocumentOwnerPolicy::class];
    }

    public function bindings(): array
    {
        return [
            ArticleQueries::class => ArticleQueryService::class,
            PublicArticleQueries::class => PublicArticleService::class,
            ArticleAdministration::class => ArticleAdministrationService::class,
            ArticleCategoryAdministration::class => ArticleCategoryAdministrationService::class,
        ];
    }
}
