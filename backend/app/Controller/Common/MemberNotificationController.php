<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Notification\MemberNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 会员站内消息。 */
final class MemberNotificationController extends AbstractController
{
    public function __construct(private MemberNotificationService $notices) {}

    /** 读取本人消息列表和未读数量。 */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);
        $result = $this->notices->page((int) $request->user()->getKey(), (int) ($input['page'] ?? 1));

        return $this->success($result->getData());
    }

    public function unread(Request $request): JsonResponse
    {
        return $this->success($this->notices->unread((int) $request->user()->getKey())->getData());
    }

    public function show(Request $request, int $notice): JsonResponse
    {
        $result = $this->notices->show((int) $request->user()->getKey(), $notice);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, 404);
    }

    /** 根据当前看到的消息标记已读。 */
    public function read(Request $request, int $notice): JsonResponse
    {
        $input = $request->validate(['through' => ['sometimes', 'boolean:strict']]);
        $result = $this->notices->markRead((int) $request->user()->getKey(), $notice, $input['through'] ?? false);

        return $result->isSuccess()
            ? $this->success($result->getData())
            : ResponseResult::json($result, 404);
    }
}
