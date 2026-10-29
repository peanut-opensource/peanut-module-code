<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Model;

use app\common\model\TenantOwnedModel;

/** OAuth 首登资料/手机补全的一次性受限票据。 */
class OAuthCompletionTicket extends TenantOwnedModel
{
    protected $name = 'oauth_completion_ticket';

    public static function callbackCandidates()
    {
        return (new self())->db(['tenantOwnership']);
    }
}
