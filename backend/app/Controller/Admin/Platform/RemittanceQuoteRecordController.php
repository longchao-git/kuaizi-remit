<?php

namespace App\Controller\Admin\Platform;

use App\Controller\AbstractController;
use App\Service\Remittance\MemberQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RemittanceQuoteRecordController extends AbstractController
{
    public function __invoke(Request $request, MemberQuoteService $quotes): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'record_no' => ['nullable', 'uuid'],
            'member_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = $quotes->records((int) ($filters['page'] ?? 1), $filters['record_no'] ?? null,
            isset($filters['member_id']) ? (int) $filters['member_id'] : null);

        return $this->success($result->getData());
    }
}
