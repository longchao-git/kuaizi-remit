<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
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
}
