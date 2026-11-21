<?php

declare(strict_types=1);

namespace app\common\contract\notice\sms;

use app\common\value\notice\sms\SmsDriverResult;

interface SmsDriver
{
    /** @param array<string, mixed> $variables */
    public function send(string $mobile, string $templateCode, array $variables): SmsDriverResult;
}
