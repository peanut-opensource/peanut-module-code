<?php

declare(strict_types=1);

namespace app;

use app\common\http\ApiProblem;
use app\common\http\HostApiProblemRenderer;
use app\common\exception\BusinessException;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        ApiProblem::class,
        BusinessException::class,
    ];

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // Framework response exceptions already own a complete response (including redirects).
        if ($e instanceof HttpResponseException) {
            return parent::render($request, $e);
        }
        $response = $this->app->make(HostApiProblemRenderer::class)->render($request, $e);
        if ($response instanceof Response) {
            return $response;
        }

        // 非 Application HTTP 错误交给框架处理。
        return parent::render($request, $e);
    }
}
