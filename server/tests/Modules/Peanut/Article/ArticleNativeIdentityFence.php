<?php

declare(strict_types=1);

namespace tests\Modules\Peanut\Article;

/** Explicit identity-policy substitute only. Native Document transactions and persistence remain real. */
final class ArticleNativeIdentityFence
{
    public function snapshot(...$arguments): string
    {
        return str_repeat('a', 64);
    }

    public function lockAndAssert(...$arguments): void
    {
        if (!\think\facade\Db::connect()->getPdo()->inTransaction()) {
            throw new \LogicException('Native transaction required');
        }
    }

    public function currentDigest(...$arguments): string
    {
        return str_repeat('a', 64);
    }
}
