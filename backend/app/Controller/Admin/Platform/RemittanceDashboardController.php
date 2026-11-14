<?php

namespace App\Controller\Admin\Platform;

use App\Controller\AbstractController;
use App\Service\Remittance\RemittanceDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RemittanceDashboardController extends AbstractController
{
    public function __invoke(Request $request, RemittanceDashboardService $dashboard): JsonResponse
    {
        $input = $request->validate(['days' => ['sometimes', 'integer', 'in:1,7,30']]);

        return $this->success($dashboard->summary((int) ($input['days'] ?? 7))->getData());
    }
}
