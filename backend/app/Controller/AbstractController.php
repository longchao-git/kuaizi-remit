<?php

namespace App\Controller;

use App\Common\ResponseResult;
use App\Common\Result;
use Illuminate\Http\JsonResponse;

abstract class AbstractController
{
    protected function success(mixed $data = null, string $msg = '操作成功'): JsonResponse
    {
        return ResponseResult::json(Result::success($data, $msg));
    }

    protected function fail(int $code, string $msg, mixed $data = null): JsonResponse
    {
        return ResponseResult::json(Result::fail($msg, $code, $data));
    }
}
