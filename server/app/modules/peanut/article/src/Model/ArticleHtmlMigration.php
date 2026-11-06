<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Model;

/** Atomic Article-owned receipt; native Document receipts alone do not bind source/owner/actor. */
final class ArticleHtmlMigration extends \app\common\model\TenantOwnedModel
{
    protected $name = 'article_html_migration';
    protected $autoWriteTimestamp = false;
    protected $json = ['result_json'];
    protected $jsonAssoc = true;
}
