<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Controller\AbstractController;
use App\Service\Remittance\Yaband\MemberMaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberMaterialController extends AbstractController
{
    public function __construct(private MemberMaterialService $materials) {}

    public function memberIndex(Request $request): JsonResponse
    {
        return $this->index($request, (int) $request->user()->getKey());
    }

    public function adminIndex(Request $request): JsonResponse
    {
        return $this->index($request, null);
    }

    public function register(Request $request): JsonResponse
    {
        $input = $request->validate([
            'member_id' => ['required', 'integer', 'min:1'],
            'order_reference' => ['nullable', 'uuid'],
            'question_id' => ['required', 'string', 'max:200'],
            'instruction' => ['required', 'string', 'max:500'],
            'deadline_at' => ['required', 'integer:strict', 'between:1,253402300799'],
        ]);

        return $this->respond($this->materials->register($input));
    }

    public function submit(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate(['reply' => ['required', 'string', 'max:6000'], 'document' => ['nullable', 'file', 'max:74']]);

        return $this->respond($this->materials->submit((int) $request->user()->getKey(), $reference, $input['reply'], $request->file('document')));
    }

    private function index(Request $request, ?int $member): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return $this->respond($this->materials->page((int) ($input['page'] ?? 1), $member));
    }

    private function respond(Result $result): JsonResponse
    {
        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }
}
