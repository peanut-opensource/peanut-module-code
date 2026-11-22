<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Model;

/** Article business Save receipt; not a background job or a second Document operation. *
 * @property int|string $actor_account_id
 * @property int|string $actor_member_id
 * @property int|string $document_id
 * @property string $input_sha256
 */
final class ArticleBodySave extends \app\common\model\TenantOwnedModel
{
    protected $name = 'article_body_save';
    protected $autoWriteTimestamp = false;
    protected $json = ['result_json'];
    protected $jsonAssoc = true;
}
