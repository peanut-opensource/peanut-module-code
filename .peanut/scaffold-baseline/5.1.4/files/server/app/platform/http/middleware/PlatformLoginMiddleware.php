<?php

declare(strict_types=1);

namespace app\platform\http\middleware;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\JsonResponseFactory;
use app\platform\http\PlatformRequest;
use app\platform\services\PlatformOperatorSessionService;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;

final class PlatformLoginMiddleware
{
    public function __construct(
        private readonly ExecutionContextStore $executionContexts,
        private readonly CurrentExecutionContext $executionContext,
        private readonly ApplicationHostPolicy $hosts,
        private readonly PlatformOperatorSessionService $sessions,
    ) {}

    public function handle($request, \Closure $next)
    {
        try {
            $this->hosts->assertPlatform($request);
        } catch (\DomainException|\InvalidArgumentException) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform host is not allowed.', null, 40300);
        }
        $token = PlatformRequest::bearerToken($request);
        if ($token === '') {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        try {
            $context = $this->sessions->context(
                $token,
                PlatformRequest::requestId($this->executionContext, $request),
            );
        } catch (AuthException|\DomainException|\InvalidArgumentException) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication credential is invalid.', null, 40100);
        }

        $operation = sprintf(
            'http.platform.%s.%s',
            strtolower((string) $request->method()),
            trim((string) $request->pathinfo(), '/'),
        );
        return $this->executionContexts->run(
            new \app\common\execution\PlatformExecutionContext($context, $operation),
            static fn() => $next($request),
        );
    }
}
