<?php

namespace App\Controller\Admin\Platform;

use App\Controller\AbstractController;
use App\Service\System\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlatformAuditController extends AbstractController
{
    public function __construct(private PlatformAuditService $audit) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'action' => ['sometimes', Rule::in(PlatformAuditService::ACTIONS)],
        ]);
        $result = $this->audit->page((int) ($input['page'] ?? 1), $input['action'] ?? null);

        return $this->success($result->getData());
    }
}
