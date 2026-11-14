<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Remittance\MemberQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RemittanceMarginController extends AbstractController
{
    public function __invoke(Request $request, MemberQuoteService $quotes): JsonResponse
    {
        $input = $request->validate([
            'record_no' => ['required', 'uuid'],
            'fx_income_minor' => ['required', 'integer:strict', 'between:-99999999900,99999999900'],
            'cost_minor' => ['required', 'integer:strict', 'between:0,99999999900'],
        ]);
        $result = $quotes->margin($input['record_no'], $input['fx_income_minor'], $input['cost_minor']);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, 404);
    }
}
