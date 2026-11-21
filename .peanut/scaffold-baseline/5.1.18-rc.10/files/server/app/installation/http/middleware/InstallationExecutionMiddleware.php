<?php

declare(strict_types=1);

namespace app\installation\http\middleware;

use app\common\execution\ExecutionContextStore;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\http\RequestTrace;

final readonly class InstallationExecutionMiddleware
{
    public function __construct(
        private ExecutionContextStore $contexts,
        private CurrentExecutionContext $executionContext,
    ) {}

    public function handle($request, \Closure $next)
    {
        $operation = sprintf(
            'installation.http.%s.%s',
            strtolower((string) $request->method()),
            str_replace('/', '.', trim((string) $request->pathinfo(), '/')),
        );
        return $this->contexts->run(
            new InstallationExecutionContext(
                $operation,
                RequestTrace::id($this->executionContext, $request, 'install'),
            ),
            static fn() => $next($request),
        );
    }
}
