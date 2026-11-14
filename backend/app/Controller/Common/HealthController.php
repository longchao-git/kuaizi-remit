<?php

namespace App\Controller\Common;

use App\Controller\AbstractController;
use App\Service\System\HealthService;
use Illuminate\Http\JsonResponse;

final class HealthController extends AbstractController
{
    /** 返回应用进程的健康状态。 */
    public function __invoke(HealthService $service): JsonResponse
    {
        $result = $service->check();

        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : $this->fail($result->getCode(), $result->getMsg(), $result->getData());
    }
}
