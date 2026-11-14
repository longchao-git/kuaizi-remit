<?php

namespace App\Controller\Common;

use App\Controller\AbstractController;
use App\Service\Remittance\RemittanceRuleService;
use Illuminate\Http\JsonResponse;

final class RemittanceAnnouncementController extends AbstractController
{
    public function __invoke(RemittanceRuleService $rules): JsonResponse
    {
        return $this->success($rules->memberAnnouncement()->getData());
    }
}
