<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\BaseController;
use app\common\traits\ApiResponseTrait;
use app\common\execution\PlatformExecutionContext;
use app\platform\context\PlatformOperatorContext;
use app\platform\http\PlatformRequest;

abstract class BasePlatformController extends BaseController
{
    use ApiResponseTrait;

    protected ?PlatformOperatorContext $platformContext = null;

    protected function initialize(): void
    {
        $context = $this->executionContext()->current();
        $this->platformContext = $context instanceof PlatformExecutionContext
            ? $context->platform
            : null;
    }

    protected function requestId(): string
    {
        return PlatformRequest::requestId($this->executionContext(), $this->request);
    }
}
