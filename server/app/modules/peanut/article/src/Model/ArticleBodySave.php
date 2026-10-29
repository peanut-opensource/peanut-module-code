<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Model;

/** Article business Save receipt; not a background job or a second Document operation. */
final class ArticleBodySave extends \app\common\model\TenantOwnedModel
{
    protected $name = 'article_body_save';
    protected $autoWriteTimestamp = false;
    protected $json = ['result_json'];
    protected $jsonAssoc = true;
}
