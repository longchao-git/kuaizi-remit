<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Remittance\MemberQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 为已登录的 App 返回 EUR 到 CNY 的报价。 */
final class MemberQuoteController extends AbstractController
{
    public function __invoke(Request $request, MemberQuoteService $quotes): JsonResponse
    {
        $input = $request->validate([
            'principal_minor' => ['required', 'integer:strict', 'between:10000,99999999900'],
            'payment_method' => ['required', 'in:sepa,applePay,card'],
            'payout_method' => ['required', 'in:wechat,bank'],
            'rate_ten_thousandths' => ['prohibited'],
            'simulate_first_order' => ['prohibited'],
            'coupon_id' => ['nullable', 'integer:strict', 'min:1'],
        ]);
        $result = $quotes->estimate($input, (int) $request->user()->getKey());

        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : ResponseResult::json($result, $result->getCode() === 409 ? 409 : 503);
    }
}
