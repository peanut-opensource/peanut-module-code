<?php

declare(strict_types=1);

namespace app\common\traits;

use app\common\http\PageResult;
use app\common\http\JsonResponseFactory;
use think\response\Json;

trait ApiResponseTrait
{
    protected function success(string $msg = 'success', mixed $data = []): Json
    {
        return JsonResponseFactory::success($msg, $data);
    }

    protected function fail(string $msg = 'fail'): never
    {
        throw \app\common\http\ApiProblem::fromEnvelope($msg);
    }

    protected function data(mixed $data): Json
    {
        return JsonResponseFactory::data($data);
    }

    protected function dataLists(PageResult $page): Json
    {
        return JsonResponseFactory::dataLists($page);
    }
}
