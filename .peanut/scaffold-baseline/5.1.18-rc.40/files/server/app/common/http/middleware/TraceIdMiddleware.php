<?php

declare(strict_types=1);

namespace app\common\http\middleware;

use app\common\execution\CurrentExecutionContext;
use app\common\http\RequestTrace;
use Closure;
use think\Request;
use think\Response;

final readonly class TraceIdMiddleware
{
    public function __construct(private CurrentExecutionContext $executionContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $id = RequestTrace::id($this->executionContext, $request);
        return RequestTrace::attach($response, $id);
    }
}
