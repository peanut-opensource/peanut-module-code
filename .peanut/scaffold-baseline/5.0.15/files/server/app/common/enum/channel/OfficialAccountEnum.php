<?php

declare(strict_types=1);

namespace app\common\enum\channel;

class OfficialAccountEnum
{
    public const REPLY_SUBSCRIBE = 1;
    public const REPLY_KEYWORD = 2;
    public const REPLY_DEFAULT = 3;

    public const MATCH_EXACT = 1;
    public const MATCH_FUZZY = 2;

    public const CONTENT_TEXT = 1;
}
