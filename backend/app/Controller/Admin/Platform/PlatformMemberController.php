<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Model\System\User;
use App\Service\Notification\MemberNotificationService;
use App\Service\System\PlatformMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 平台员工查询会员和发送个人站内通知。 */
final class PlatformMemberController extends AbstractController
{
    /** 分页查询会员，关键词支持编号、姓名和邮箱。 */
    public function index(Request $request, PlatformMemberService $members): JsonResponse
    {
        $input = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
        ]);
        $result = $members->page($input['keyword'] ?? '', (int) ($input['page'] ?? 1));

        return $this->success($result->getData());
    }

    /** 发送一条站内通知，重试时使用同一个 request_id。 */
    public function notifyMember(Request $request, User $member, MemberNotificationService $notices): JsonResponse
    {
        abort_if($member->is_platform_staff, 404);
        $input = $request->validate([
            'request_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:500'],
        ]);
        $result = $notices->send((int) $member->getKey(), (int) $request->user()->getKey(), $input);

        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : ResponseResult::json($result, 409);
    }
}
