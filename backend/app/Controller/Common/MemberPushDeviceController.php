<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Notification\MemberPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberPushDeviceController extends AbstractController
{
    public function register(Request $request, MemberPushService $push): JsonResponse
    {
        $input = $request->validate(['client_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,128}$/D']]);
        $result = $push->register((int) $request->user()->getKey(), (int) $request->user()->currentAccessToken()->getKey(), $input['client_id']);

        return $result->isSuccess() ? $this->success(true) : ResponseResult::json($result, $result->getCode());
    }

    public function unregister(Request $request, MemberPushService $push): JsonResponse
    {
        $push->unregister((int) $request->user()->getKey(), (int) $request->user()->currentAccessToken()->getKey());

        return $this->success(true);
    }
}
