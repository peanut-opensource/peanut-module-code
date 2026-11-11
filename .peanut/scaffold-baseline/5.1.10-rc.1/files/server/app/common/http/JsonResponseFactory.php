<?php

declare(strict_types=1);

namespace app\common\http;

use app\common\http\PageResult;
use think\response\Json;

class JsonResponseFactory
{
    public static function success(string $msg = 'success', mixed $data = [], int $code = 20000): Json
    {
        return self::response($code, $msg, $data);
    }

    public static function data(mixed $data): Json
    {
        if ($data instanceof PageResult) {
            $data = $data->responseData();
        }
        return self::response(20000, 'success', $data);
    }

    public static function dataLists(PageResult $page): Json
    {
        return self::data($page);
    }

    public static function response(int $code, string $msg, mixed $data = null, int $status = 200): Json
    {
        return json(compact('code', 'msg', 'data'), $status);
    }
}
