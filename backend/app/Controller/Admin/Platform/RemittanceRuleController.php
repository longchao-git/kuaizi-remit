<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Controller\AbstractController;
use App\Permission\PlatformRoles;
use App\Permission\RemittancePermission;
use App\Service\Remittance\RemittanceRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RemittanceRuleController extends AbstractController
{
    public function __construct(private RemittanceRuleService $rules) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeRules($request);

        return $this->respond($this->rules->get());
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeRules($request);
        $validation = [
            'revision_id' => ['sometimes', 'string', 'regex:/^[0-9]{1,18}$/D'],
            'base_fee_minor' => ['required', 'integer:strict', 'between:0,1000000'],
            'first_order_free' => ['required', 'boolean:strict'],
            'spread_basis_points' => ['sometimes', 'integer:strict', 'between:0,9999'],
            'payment' => ['required', 'array:sepa,applePay,card'],
            'eta' => ['required', 'array:wechat,bank'],
        ];

        foreach (['sepa', 'applePay', 'card'] as $method) {
            $validation["payment.$method"] = ['required', 'array:basis_points,notice,notice_enabled'];
            $validation["payment.$method.basis_points"] = ['required', 'integer:strict', 'between:0,10000'];
            $validation["payment.$method.notice"] = ['nullable', 'string', 'max:200'];
            $validation["payment.$method.notice_enabled"] = ['sometimes', 'boolean:strict'];
        }

        foreach (['wechat', 'bank'] as $method) {
            $validation["eta.$method"] = ['required', 'array:threshold_minor,below,at_or_above,bands'];
            $validation["eta.$method.threshold_minor"] = ['required', 'integer:strict', 'between:1,99999999900'];
            $validation["eta.$method.below"] = ['required', 'string', 'max:80'];
            $validation["eta.$method.at_or_above"] = ['required', 'string', 'max:80'];
            $validation["eta.$method.bands"] = ['sometimes', 'array', 'list', 'max:10'];
            $validation["eta.$method.bands.*"] = ['required', 'array:from_minor,until_minor,label,enabled'];
            $validation["eta.$method.bands.*.from_minor"] = ['required', 'integer:strict', 'between:0,99999999900'];
            $validation["eta.$method.bands.*.until_minor"] = ['present', 'nullable', 'integer:strict', 'between:1,99999999900'];
            $validation["eta.$method.bands.*.label"] = ['required', 'string', 'max:80'];
            $validation["eta.$method.bands.*.enabled"] = ['required', 'boolean:strict'];
        }

        $settings = $request->validate($validation);
        $revision = $settings['revision_id'] ?? null;
        unset($settings['revision_id']);
        $result = $this->rules->save($settings, $request->user(), $revision);

        return $result->isFail() ? ResponseResult::json($result, $result->getCode()) : $this->respond($result);
    }

    public function updateOperations(Request $request): JsonResponse
    {
        $this->authorizeRules($request);
        $validation = [
            'revision_id' => ['required', 'string', 'regex:/^[0-9]{1,18}$/D'],
            'announcement' => ['required', 'array:enabled,text'],
            'announcement.enabled' => ['required', 'boolean:strict'],
            'announcement.text' => ['present', 'nullable', 'required_if:announcement.enabled,true', 'string', 'max:200'],
            'payment' => ['required', 'array:sepa,applePay,card'],
        ];
        foreach (['sepa', 'applePay', 'card'] as $method) {
            $validation["payment.$method"] = ['required', 'array:notice,notice_enabled'];
            $validation["payment.$method.notice"] = ['present', 'nullable', "required_if:payment.$method.notice_enabled,true", 'string', 'max:200'];
            $validation["payment.$method.notice_enabled"] = ['required', 'boolean:strict'];
        }

        $result = $this->rules->saveOperations($request->validate($validation), $request->user());

        return $result->isSuccess() ? $this->respond($result) : ResponseResult::json($result, $result->getCode());
    }

    /** 读取配置保存记录。 */
    public function revisions(Request $request): JsonResponse
    {
        $this->authorizeRules($request);
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return $this->respond($this->rules->revisions((int) ($input['page'] ?? 1)));
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeRules($request);

        return $this->respond($this->rules->preview($request->validate([
            'principal_minor' => ['required', 'integer:strict', 'between:1,99999999900'],
            'rate_ten_thousandths' => ['required', 'integer:strict', 'between:1,1000000'],
            'payment_method' => ['required', 'in:sepa,applePay,card'],
            'payout_method' => ['required', 'in:wechat,bank'],
            'simulate_first_order' => ['sometimes', 'boolean:strict'],
        ])));
    }

    private function authorizeRules(Request $request): void
    {
        $user = $request->user();
        abort_unless(PlatformRoles::allows($user, $user->currentAccessToken(), RemittancePermission::MANAGE_RULES), 403);
    }

    private function respond(Result $result): JsonResponse
    {
        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : $this->fail($result->getCode(), $result->getMsg());
    }
}
