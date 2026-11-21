<?php

declare(strict_types=1);

namespace app\common\validate;

use PeanutAdmin\Kernel\Identity\PasswordPolicy;

/** Validator boundary; credential services enforce the same resolved Core implementation. */
trait PasswordRule
{
    protected function passwordPolicy($value, $rule, array $data): bool|string
    {
        if (!is_string($value)) {
            return '密码必须是字符串';
        }
        try {
            app()->make(PasswordPolicy::class)->assertValid($value);
            return true;
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
