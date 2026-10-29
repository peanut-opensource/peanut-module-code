<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Model;

use app\common\model\TenantOwnedModel;

/** 浏览器 OAuth state 的一次性服务端记录，仅保存 state 哈希。 */
class OAuthAttempt extends TenantOwnedModel
{
    protected $name = 'oauth_attempt';

    public static function callbackCandidates()
    {
        return (new self())->db(['tenantOwnership']);
    }
}
