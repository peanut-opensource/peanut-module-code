<?php

declare(strict_types=1);

namespace tests\Modules\Peanut\Article;

/** Policy-side audit substitute records on the same native transaction for rollback evidence. */
final class ArticleNativeAudit
{
    public function appendTenantMember($context, string $event, ...$metadata): void
    {
        \think\facade\Db::name('article_native_audit')->insert(['event' => $event]);
    }
}
