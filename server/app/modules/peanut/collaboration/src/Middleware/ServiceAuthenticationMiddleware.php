<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Middleware;

use app\common\exception\BusinessException;
use app\common\http\HostApiProblemRenderer;
use PeanutAdmin\Modules\Collaboration\Service\ServiceAuthentication;
use think\Request;
use think\Response;

/** Authentication is based on the exact HTTP bytes, never hydrated identity parameters. */
final readonly class ServiceAuthenticationMiddleware
{
    public function __construct(private ServiceAuthentication $authentication, private HostApiProblemRenderer $problems) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->header('authorization', '') !== '' || $request->header('cookie', '') !== '' || $request->get() !== []
            || $request->method() !== 'POST' || strtolower(trim(explode(';', $request->header('content-type', ''))[0])) !== 'application/json') {
            throw BusinessException::forbidden('COLLABORATION_INTERNAL_AUDIENCE_INVALID', '内部接口仅接受独立服务请求');
        }
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (!is_string($path)) {
            throw BusinessException::invalid('COLLABORATION_SERVICE_REQUEST_INVALID', '内部请求路径无效');
        }
        $headers = [];
        foreach (['protocol','service_instance_id','key_id','holder_key','timestamp','nonce','signature'] as $field) {
            $header = 'x-collaboration-' . str_replace('_', '-', $field === 'service_instance_id' ? 'service-instance' : $field);
            $headers[$field] = $request->header($header, '');
        }
        $proof = $this->authentication->authenticate($request->method(), $path, $request->getInput(), $headers);
        $response = $this->authentication->run($proof, function () use ($request, $next): Response {
            try {
                return $next($request);
            } catch (\Throwable $error) {
                $response = $this->problems->render($request, $error);
                if ($response === null) {
                    throw $error;
                }
                return $response;
            }
        });
        return $response->header($this->authentication->responseHeaders($proof, $response->getCode(), $response->getContent()));
    }
}
