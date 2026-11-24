<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Remittance\MemberClosureExecutionService;
use App\Service\Remittance\MemberClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberClosureController extends AbstractController
{
    public function __construct(private MemberClosureService $closures) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'member_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ]);
        $result = $this->closures->page((int) ($input['page'] ?? 1),
            isset($input['member_id']) ? (int) $input['member_id'] : null, $input['status'] ?? null, staff: true);

        return $this->success($result->getData());
    }

    public function review(Request $request, int $closure): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['required', 'string', 'max:300'],
        ], ['note.required' => '请输入给用户的审核说明']);
        $result = $this->closures->review($closure, (int) $request->user()->getKey(), $input['decision'], $input['note']);

        return ResponseResult::json($result, $result->isSuccess() ? 200 : $result->getCode());
    }

    /** 在原注销管理权限下单独确认执行，不把保存审核结果当作立即停用。 */
    public function execute(Request $request, int $closure, MemberClosureExecutionService $execution): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']], ['confirm.accepted' => '请确认执行账号注销']);
        $result = $execution->execute($closure, (int) $request->user()->getKey());

        return ResponseResult::json($result, $result->isSuccess() ? 200 : $result->getCode());
    }

    /** 未确认的注销仅查询上游状态，不重放注销请求。 */
    public function verify(Request $request, int $closure, MemberClosureExecutionService $execution): JsonResponse
    {
        $result = $execution->verify($closure, (int) $request->user()->getKey());

        return ResponseResult::json($result, $result->isSuccess() ? 200 : $result->getCode());
    }
}
