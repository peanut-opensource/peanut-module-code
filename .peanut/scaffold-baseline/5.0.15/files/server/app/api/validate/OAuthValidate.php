<?php

declare(strict_types=1);

namespace app\api\validate;

use think\Validate;

class OAuthValidate extends Validate
{
    protected $rule = [
        'scene' => 'require|in:mnp,oa,open_pc',
        'return_path' => 'require|max:500',
        'code' => 'require|max:2048',
        'state' => 'require|regex:/^[a-f0-9]{64}$/',
        'ticket' => 'require|regex:/^[a-f0-9]{64}$/',
        'nickname' => 'max:50',
        'avatar' => 'max:1000',
        'mobile' => 'max:20',
        'verification_code' => 'max:12',
        'client_id' => 'max:191',
    ];

    protected $scene = [
        'begin' => ['scene', 'return_path', 'client_id'],
        'callback' => ['scene', 'code', 'state'],
        'mnp' => ['code', 'client_id'],
        'bind' => ['scene', 'code'],
        'complete' => ['ticket', 'nickname', 'avatar', 'mobile', 'verification_code'],
    ];
}
