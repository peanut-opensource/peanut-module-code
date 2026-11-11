<?php

declare(strict_types=1);

namespace app\common\http;

use app\common\execution\CurrentExecutionContext;
use app\common\http\JsonResponseFactory;
use app\common\infrastructure\runtime\OperationalLog;
use think\App;
use think\Response;

/** Renders one stable JSON error envelope for each real HTTP Application. */
final readonly class HostApiProblemRenderer
{
    private const FALLBACKS = [
        'adminapi' => ['ADMINAPI_UNEXPECTED_FAILURE', '服务暂时不可用'],
        'api' => ['API_UNEXPECTED_FAILURE', '服务暂时不可用'],
        'platform' => ['PLATFORM_UNEXPECTED_FAILURE', 'Platform request failed.'],
        'installation' => ['INSTALLATION_UNEXPECTED_FAILURE', '安装服务暂时不可用。'],
    ];

    public function __construct(
        private App $app,
        private CurrentExecutionContext $executionContext,
        private ApiProblemMapper $problems,
    ) {}

    public function render($request, \Throwable $exception): ?Response
    {
        $application = $this->app->http->getName();
        $problem = $this->problems->map($exception);
        $diagnostic = [];
        if (!$problem instanceof ApiProblem) {
            $fallback = self::FALLBACKS[$application] ?? null;
            if ($fallback === null) {
                return null;
            }
            $problem = new ApiProblem($fallback[0], 500, $fallback[1]);
            $diagnostic = $this->unexpectedDiagnostic($exception);
        }

        $requestId = RequestTrace::id($this->executionContext, $request, $application !== '' ? $application : 'http');
        OperationalLog::warning($this->executionContext, 'api_problem', [
            'application' => $application !== '' ? $application : 'unknown',
            'method' => $request->method(),
            'path' => '/' . ltrim($request->pathinfo(), '/'),
            'error_code' => $problem->errorCode,
            'api_code' => $problem->apiCode(),
            'request_id' => $requestId,
        ] + $diagnostic);

        return JsonResponseFactory::response(
            $problem->apiCode(),
            $problem->getMessage(),
            $problem->data(),
            $problem->httpStatus,
        )->header(['X-Request-Id' => $requestId] + $problem->headers);
    }

    /** @return array{exception_class:string,exception_file:string,exception_line:int,exception_trace:list<array{class:?string,function:?string,line:?int}>} */
    private function unexpectedDiagnostic(\Throwable $exception): array
    {
        $trace = [];
        foreach (array_slice($exception->getTrace(), 0, 5) as $frame) {
            $trace[] = [
                'class' => is_string($frame['class'] ?? null) ? $frame['class'] : null,
                'function' => is_string($frame['function'] ?? null) ? $frame['function'] : null,
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
            ];
        }

        return [
            'exception_class' => $exception::class,
            'exception_file' => $this->safeFile($exception->getFile()),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $trace,
        ];
    }

    private function safeFile(string $file): string
    {
        $root = realpath($this->app->getRootPath());
        $resolved = realpath($file);
        if (is_string($root) && is_string($resolved)
            && str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return substr($resolved, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
        }

        return basename($file);
    }
}
