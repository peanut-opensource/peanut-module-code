<?php

use app\ExceptionHandle;
use app\Request;
use app\common\value\installation\ServerReleaseIdentity;
use app\common\infrastructure\installation\VerifiedServerDeployment;
use think\App;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\SystemClock;

// 容器Provider定义文件
return [
    VerifiedServerDeployment::class => static fn(App $app): VerifiedServerDeployment => VerifiedServerDeployment::read($app),
    ServerReleaseIdentity::class => static fn(App $app): ServerReleaseIdentity => $app->runningInConsole()
        ? ServerReleaseIdentity::load($app->getRootPath())
        : ServerReleaseIdentity::admitted($app->make(VerifiedServerDeployment::class)),
    Clock::class => SystemClock::class,
    // Native application binding: replace this entry to supply an application implementation.
    PasswordPolicy::class => static function (): PasswordPolicy {
        $minimum = config('peanut.password.minimum_length', PasswordPolicy::DEFAULT_MINIMUM_LENGTH);
        $maximum = config('peanut.password.maximum_length', PasswordPolicy::DEFAULT_MAXIMUM_LENGTH);
        if (!is_int($minimum) || !is_int($maximum)) {
            throw new \InvalidArgumentException('Password policy bounds must be integers.');
        }
        return new PasswordPolicy($minimum, $maximum);
    },
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,
];
