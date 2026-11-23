<?php

declare(strict_types=1);

namespace app\platform\http\middleware;

use app\common\http\JsonResponseFactory;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;

final class PlatformHostMiddleware
{
    public function __construct(private readonly ApplicationHostPolicy $hosts) {}

    public function handle($request, \Closure $next)
    {
        try {
            $this->hosts->assertPlatform($request);
        } catch (\DomainException|\InvalidArgumentException) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform host is not allowed.', null, 40300);
        }

        return $next($request);
    }
}
