<?php

use app\ExceptionHandle;
use app\Request;
use app\common\value\installation\ServerReleaseIdentity;
use think\App;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\SystemClock;

// 容器Provider定义文件
return [
    ServerReleaseIdentity::class => static fn(App $app): ServerReleaseIdentity => ServerReleaseIdentity::load($app->getRootPath()),
    Clock::class => SystemClock::class,
    // Native application binding: replace this entry to supply an application implementation.
    PasswordPolicy::class => static function (): PasswordPolicy {
        $minimum = config('peanut.password.minimum_length', PasswordPolicy::DEFAULT_MINIMUM_LENGTH);
        $maximum = config('peanut.password.maximum_length', PasswordPolicy::DEFAULT_MAXIMUM_LENGTH);
        if (!is_int($minimum) || !is_int($maximum)) {
            throw new \InvalidArgumentException('Password policy bounds must be integers.');
        }
        $requirements = [];
        foreach (['require_uppercase', 'require_lowercase', 'require_digit', 'require_special'] as $key) {
            $value = config('peanut.password.' . $key, false);
            if (!is_bool($value)) {
                throw new \InvalidArgumentException('Password policy requirements must be booleans.');
            }
            $requirements[] = $value;
        }
        return new PasswordPolicy($minimum, $maximum, ...$requirements);
    },
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,
];
