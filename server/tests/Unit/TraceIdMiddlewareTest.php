<?php

declare(strict_types=1);

use app\ExceptionHandle;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\RequestTrace;
use app\common\infrastructure\runtime\OperationalLog;
use app\common\http\middleware\TraceIdMiddleware;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\HttpResponseException;
use think\Request;
use think\Response;

final class TraceIdMiddlewareTest extends TestCase
{
    public function testResponseCarriesTheSameValidatedOrGeneratedRequestId(): void
    {
        $context = new CurrentExecutionContext(new ExecutionContextStore());
        $middleware = new TraceIdMiddleware($context);
        foreach (['client-123', "bad id\n"] as $header) {
            $request = (new Request())->withHeader(['X-Request-Id' => $header]);
            $seen = null;
            $response = $middleware->handle($request, function ($request) use ($context, &$seen) {
                $seen = RequestTrace::id($context, $request);
                return Response::create('ok')->header(['access-control-expose-headers' => 'ETag, Content-Disposition']);
            });
            self::assertSame($seen, $response->getHeader('X-Request-Id'));
            self::assertSame($seen, $response->getHeader('X-Trace-Id'));
            self::assertSame('ETag, Content-Disposition, X-Trace-Id', $response->getHeader('access-control-expose-headers'));
            self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $seen);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOperationalLogUsesTheBoundHttpTraceWithoutExecutionContext(): void
    {
        $app = new class(dirname(__DIR__, 2)) extends App {
            public function runningInConsole(): bool
            {
                return false;
            }
        };
        require_once $app->getThinkPath() . 'helper.php';
        $app->config->set([
            'default' => 'file',
            'channels' => ['file' => ['type' => 'File']],
        ], 'log');
        $context = new CurrentExecutionContext(new ExecutionContextStore());
        $middleware = new TraceIdMiddleware($context);
        foreach (['fixture-log-123', 'invalid header'] as $header) {
            $request = (new Request())->withHeader(['X-Request-Id' => $header]);
            $app->instance('request', $request);
            $response = $middleware->handle($request, static function () use ($context) {
                OperationalLog::info($context, 'fixture.http', ['request_id' => 'incorrect']);
                return Response::create('ok');
            });
            $logs = $app->log->getLog();
            $entry = json_decode(end($logs)->message, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('fixture.http', $entry['event']);
            self::assertSame($response->getHeader('X-Trace-Id'), $entry['attributes']['request_id']);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExceptionResponsesCarryTheTraceWithoutLeakingTheCause(): void
    {
        $app = new App(dirname(__DIR__, 2));
        require_once $app->getThinkPath() . 'helper.php';
        $app->http->name('adminapi');
        $app->instance(CurrentExecutionContext::class, new CurrentExecutionContext(new ExecutionContextStore()));
        $handler = new ExceptionHandle($app);
        $request = (new Request())->withHeader(['X-Request-Id' => 'fixture-error-123']);
        $response = $handler->render($request, new \RuntimeException('SELECT private_data FROM internal_table'));
        self::assertSame(500, $response->getCode());
        self::assertSame('fixture-error-123', $response->getHeader('X-Trace-Id'));
        self::assertSame('X-Trace-Id', $response->getHeader('Access-Control-Expose-Headers'));
        self::assertStringNotContainsString('internal_table', $response->getContent());

        $redirect = Response::create('', 'html', 302)->header(['Location' => '/login']);
        $wrapped = $handler->render($request, new HttpResponseException($redirect));
        self::assertSame('/login', $wrapped->getHeader('Location'));
        self::assertSame('fixture-error-123', $wrapped->getHeader('X-Trace-Id'));
    }
}
