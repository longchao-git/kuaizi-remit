<?php

namespace App\Common;

use Illuminate\Http\JsonResponse;

final class ResponseResult
{
    /** 将业务结果转成 API 的统一响应格式。 */
    public static function json(Result $result, int $httpStatus = 200): JsonResponse
    {
        return new JsonResponse([
            'code' => $result->getCode(),
            'msg' => $result->getMsg(),
            'data' => $result->getData() ?? (object) [],
        ], $httpStatus);
    }
}
