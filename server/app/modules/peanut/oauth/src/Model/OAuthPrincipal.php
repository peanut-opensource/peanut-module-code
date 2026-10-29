<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Model;

use app\common\model\TenantOwnedModel;

/** 同一微信 UnionID 在本系统内的唯一会员归属。 */
class OAuthPrincipal extends TenantOwnedModel
{
    protected $name = 'oauth_principal';
}
