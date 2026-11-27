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

    /** 按本人订单状态分页，省略筛选时兼容原列表。 */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'status' => ['sometimes', 'in:processing,completed,refund'],
        ]);
        $status = $input['status'] ?? null;
        $result = $this->orders->page((int) ($input['page'] ?? 1), (int) $request->user()->getKey(), filters: ['member_status' => $status]);
        $payload = $result->getData();
        if ($status !== null) {
            $payload['status_filter'] = $status;
        }

        return $this->success($payload);
    }

    /** 读取个人中心汇款金额，统计范围仅包括当前会员。 */
    public function summary(Request $request): JsonResponse
    {
        return $this->success($this->orders->memberSummary((int) $request->user()->getKey()));
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $result = $this->orders->details($reference, (int) $request->user()->getKey());

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, 404);
    }

    public function checkout(Request $request, MemberCheckoutService $checkout): JsonResponse
    {
        $input = $request->validate([
            'quote_reference' => ['required', 'uuid'], 'payout_account' => ['required', 'uuid'], 'declaration' => ['sometimes', 'array'],
        ]);
        $result = $checkout->submit($request->user(), $input['quote_reference'], strtolower($input['payout_account']), $request->ip() ?? '', $input['declaration'] ?? null);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }

    /** 本人主动查单；路由限频，状态更新复用已有核对逻辑。 */
    public function refresh(Request $request, string $reference): JsonResponse
    {
        $result = $this->orders->refreshForMember($reference, (int) $request->user()->getKey());

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }

    public function accounts(Request $request, MemberCheckoutService $checkout): JsonResponse
    {
        $input = $request->validate(['payout_method' => ['required', 'in:wechat,bank']]);
        $result = $checkout->accounts($request->user(), $input['payout_method']);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }
}
