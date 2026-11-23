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

    /** 授权编辑者读取后台草稿及当前公开版本。 */
    public function editor(string $kind, AgreementService $agreements): JsonResponse
    {
        return $this->success($agreements->editor($kind))->header('Cache-Control', 'private, no-store');
    }

    /** 校验正文和客户端修订号，保存但不发布草稿。 */
    public function saveDraft(string $kind, Request $request, AgreementService $agreements): JsonResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:30000'],
            'draft_revision' => ['present', 'nullable', 'uuid'],
        ]);

        return $this->success($agreements->saveDraft(
            $kind, $input['body'], (int) $request->user()->getKey(), $input['draft_revision'],
        ));
    }

    /** 校验并发布纯文本正文。 */
    public function publish(string $kind, Request $request, AgreementService $agreements): JsonResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:30000'],
            'draft_revision' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return $this->success($agreements->publish(
            $kind, $input['body'], (int) $request->user()->getKey(),
            $input['draft_revision'] ?? null, array_key_exists('draft_revision', $input),
        ));
    }
}
