<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

class ArticleCate extends TenantOwnedModel
{
    use SoftDelete;
    protected $name       = 'article_cate';
    protected $deleteTime = 'delete_time';
}
