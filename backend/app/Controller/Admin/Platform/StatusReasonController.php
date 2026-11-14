<?php

namespace App\Controller\Admin\Platform;

use App\Controller\AbstractController;
use App\Service\Remittance\StatusReasonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StatusReasonController extends AbstractController
{
    public function __invoke(Request $request, StatusReasonService $reasons): JsonResponse
    {
        $input = $request->validate(['keyword' => ['nullable', 'string', 'max:100']]);
        $result = $reasons->list($input['keyword'] ?? '');

        return $this->success($result->getData());
    }
}
