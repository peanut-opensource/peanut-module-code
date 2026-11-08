<?php

declare(strict_types=1);

namespace app\common\persistence;

use app\common\http\PageResult;
use think\Model;

trait ConvertsModelPage
{
    public static function arrayPage(PageResult $page): PageResult
    {
        return $page->map(static fn(mixed $item): array => $item instanceof Model
            ? $item->toArray()
            : (array) $item);
    }
}
