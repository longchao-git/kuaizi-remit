<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Remittance\RemittanceOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RemittanceOrderController extends AbstractController
{
    public function __construct(private RemittanceOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'reference' => ['nullable', 'uuid'],
            'member_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'in:sepa,applePay,card'],
            'payout_method' => ['nullable', 'in:wechat,bank'],
            'status' => ['nullable', 'in:pending_payment,processing,paid_out,refunding,refunded,exception'],
        ]);
        $result = $this->orders->page((int) ($filters['page'] ?? 1),
            isset($filters['member_id']) ? (int) $filters['member_id'] : null,
            $filters['reference'] ?? null, staff: true, filters: $filters);

        return $this->success($result->getData());
    }

    /** 获取单个订单的当前状态及报价快照。 */
    public function show(string $reference): JsonResponse
    {
        $result = $this->orders->staffDetails($reference);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }

    public function refresh(string $reference): JsonResponse
    {
        $result = $this->orders->refresh($reference);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }
}
