<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Controller\AbstractController;
use App\Service\Remittance\RemittanceCouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** App 和员工优惠券接口，权限由路由中间件检查。 */
final class RemittanceCouponController extends AbstractController
{
    public function __construct(private RemittanceCouponService $coupons) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);
        $member = $request->routeIs('coupon.member.*') ? (int) $request->user()->getKey() : null;

        return $this->respond($this->coupons->page((int) ($input['page'] ?? 1), $member));
    }

    public function issue(Request $request): JsonResponse
    {
        $input = $request->validate([
            'delivery' => ['sometimes', 'in:home_popup,code'],
            'request_id' => ['required', 'uuid'],
            'template_id' => ['nullable', 'integer:strict', 'min:1'],
            'title' => ['required_without:template_id', 'prohibits:template_id', 'string', 'max:80'],
            'discount_bps' => ['required_without:template_id', 'prohibits:template_id', 'integer:strict', 'between:1,10000'],
            'valid_days' => ['required_without:template_id', 'prohibits:template_id', 'integer:strict', 'between:1,365'],
            'member_id' => ['required_if:delivery,home_popup', 'prohibited_if:delivery,code', 'nullable', 'integer:strict', 'min:1'],
        ]);
        $input['delivery'] ??= isset($input['member_id']) ? 'direct' : 'code';

        return $this->respond($this->coupons->issue($input, (int) $request->user()->getKey()));
    }

    public function templates(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'enabled_only' => ['sometimes', 'boolean'],
        ]);

        return $this->respond($this->coupons->templates((int) ($input['page'] ?? 1), (bool) ($input['enabled_only'] ?? false)));
    }

    public function saveTemplate(Request $request, ?int $template = null): JsonResponse
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'discount_bps' => ['required', 'integer:strict', 'between:1,10000'],
            'valid_days' => ['required', 'integer:strict', 'between:1,365'],
            'payout_method' => ['present', 'nullable', 'in:wechat,bank'],
            'enabled' => ['required', 'boolean:strict'],
        ]);

        return $this->respond($this->coupons->saveTemplate($input, $template));
    }

    public function claim(Request $request): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{20}$/D']]);

        return $this->respond($this->coupons->claim($input['code'], (int) $request->user()->getKey()));
    }

    public function homeOffer(Request $request): JsonResponse
    {
        return $this->respond($this->coupons->homeOffer((int) $request->user()->getKey()));
    }

    public function claimHomeOffer(Request $request, int $coupon): JsonResponse
    {
        return $this->respond($this->coupons->claimHomeOffer($coupon, (int) $request->user()->getKey()));
    }

    public function revoke(Request $request, int $coupon): JsonResponse
    {
        return $this->respond($this->coupons->revoke($coupon, (int) $request->user()->getKey()));
    }

    private function respond(Result $result): JsonResponse
    {
        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : ResponseResult::json($result, $result->getCode());
    }
}
