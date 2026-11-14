<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Remittance\RemittanceOrderService;
use App\Service\Remittance\Yaband\MemberCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberOrderController extends AbstractController
{
    public function __construct(private RemittanceOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);
        $result = $this->orders->page((int) ($filters['page'] ?? 1), (int) $request->user()->getKey());

        return $this->success($result->getData());
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $result = $this->orders->details($reference, (int) $request->user()->getKey());

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, 404);
    }

    public function checkout(Request $request, MemberCheckoutService $checkout): JsonResponse
    {
        $input = $request->validate(['quote_reference' => ['required', 'uuid'], 'payout_account' => ['required', 'uuid']]);
        $result = $checkout->submit($request->user(), $input['quote_reference'], strtolower($input['payout_account']), $request->ip() ?? '');

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }

    public function accounts(Request $request, MemberCheckoutService $checkout): JsonResponse
    {
        $input = $request->validate(['payout_method' => ['required', 'in:wechat,bank']]);
        $result = $checkout->accounts($request->user(), $input['payout_method']);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }
}
