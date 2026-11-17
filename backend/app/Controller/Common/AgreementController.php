<?php

namespace App\Controller\Common;

use App\Controller\AbstractController;
use App\Service\Remittance\AgreementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgreementController extends AbstractController
{
    /** 登录前读取协议的最新公开版本。 */
    public function show(string $kind, AgreementService $agreements): JsonResponse
    {
        return $this->success(['document' => $agreements->latest($kind)]);
    }

    /** 校验并发布纯文本正文。 */
    public function publish(string $kind, Request $request, AgreementService $agreements): JsonResponse
    {
        $input = $request->validate(['body' => ['required', 'string', 'max:30000']]);

        return $this->success($agreements->publish($kind, $input['body'], (int) $request->user()->getKey()));
    }
}
