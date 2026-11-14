<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Model\System\User;
use App\Permission\PlatformRoles;
use App\Permission\RemittancePermission;
use App\Service\Support\SupportMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class SupportMessageController extends AbstractController
{
    public function __construct(private SupportMessageService $messages) {}

    public function index(Request $request, ?User $member = null): JsonResponse
    {
        $memberId = $this->resolveMember($request, $member);
        $input = $request->validate(['after_id' => ['sometimes', 'integer', 'min:0']]);
        $after = isset($input['after_id']) ? (int) $input['after_id'] : null;
        $result = $this->messages->history($memberId, $after);

        return $this->success($result->getData(), $result->getMsg());
    }

    public function store(Request $request, ?User $member = null): JsonResponse
    {
        $memberId = $this->resolveMember($request, $member);
        $input = $request->validate([
            'client_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:500'],
        ]);
        $result = $this->messages->send($memberId, (int) $request->user()->getKey(), $input['client_id'], $input['body']);

        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : $this->fail($result->getCode(), $result->getMsg());
    }

    /** 读取 App 当前账号的客服未读数。 */
    public function memberStatus(Request $request): JsonResponse
    {
        return $this->success($this->messages->memberStatus((int) $request->user()->getKey())->getData());
    }

    /** 标记已收到的会话消息，会员和客服分别保存自己的进度。 */
    public function read(Request $request, ?User $member = null): JsonResponse
    {
        $memberId = $this->resolveMember($request, $member);
        $input = $request->validate(['message_id' => ['required', 'integer:strict', 'min:1']]);
        $side = $request->routeIs('support.member.*') ? 'member' : 'staff';
        $result = $this->messages->read($memberId, $side, $input['message_id']);

        return $result->isSuccess()
            ? $this->success($result->getData())
            : ResponseResult::json($result, 404);
    }

    /** 后台查询有历史消息的会话。 */
    public function conversations(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'unread_only' => ['sometimes', 'boolean'],
        ]);
        $result = $this->messages->conversations((int) ($input['page'] ?? 1), (bool) ($input['unread_only'] ?? false));

        return $this->success($result->getData());
    }

    private function resolveMember(Request $request, ?User $member): int
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403);
        if ($request->routeIs('support.member.*')) {
            abort_if($user->is_platform_staff || $token->name === 'platform', 403);

            return (int) $user->getKey();
        }

        abort_unless(PlatformRoles::allows($user, $token, RemittancePermission::SUPPORT), 403);
        abort_if($member === null || $member->is_platform_staff, 404);

        return (int) $member->getKey();
    }
}
