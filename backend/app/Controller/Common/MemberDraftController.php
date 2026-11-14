<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Remittance\MemberDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberDraftController extends AbstractController
{
    public function __construct(private MemberDraftService $drafts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);
        $result = $this->drafts->page((int) $request->user()->getKey(), (int) ($filters['page'] ?? 1));

        return $this->success($result->getData());
    }

    public function save(Request $request, string $reference): JsonResponse
    {
        $result = $this->drafts->save((int) $request->user()->getKey(), $reference);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, 404);
    }

    public function remove(Request $request, string $reference): JsonResponse
    {
        $this->drafts->remove((int) $request->user()->getKey(), $reference);

        return $this->success();
    }
}
