<?php

namespace App\Controller\Common;

use App\Controller\AbstractController;
use App\Service\Remittance\MemberClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberClosureController extends AbstractController
{
    public function __construct(private MemberClosureService $closures) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return $this->success($this->closures->page((int) ($input['page'] ?? 1), (int) $request->user()->getKey())->getData());
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $result = $this->closures->submit((int) $request->user()->getKey(), $input['reason'] ?? null);

        return $this->success($result->getData(), $result->getMsg());
    }
}
