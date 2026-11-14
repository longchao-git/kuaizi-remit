<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Controller\AbstractController;
use App\Service\Remittance\Yaband\MemberKycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberKycController extends AbstractController
{
    public function __construct(private MemberKycService $kyc) {}

    public function status(Request $request): JsonResponse
    {
        return $this->respond($this->kyc->status($request->user()));
    }

    public function start(Request $request): JsonResponse
    {
        return $this->respond($this->kyc->start($request->user()));
    }

    public function profile(Request $request): JsonResponse
    {
        $input = $request->validate([
            'first_name' => ['required', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'nationality' => ['required', 'regex:/^[A-Z]{2}$/D'],
            'id_type' => ['required', 'in:national_id,passport,residence_permit'],
            'id_number' => ['required', 'string', 'max:64'],
            'expiry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'birthday' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', 'in:M,F'],
            'address' => ['required', 'array:country,address_line1,address_line2,post_code,city'],
            'address.country' => ['required', 'regex:/^[A-Z]{2}$/D'],
            'address.address_line1' => ['required', 'string', 'max:64'],
            'address.address_line2' => ['nullable', 'string', 'max:64'],
            'address.post_code' => ['required', 'string', 'max:64'],
            'address.city' => ['required', 'string', 'max:64'],
        ]);
        if (($input['address']['address_line2'] ?? null) === null) {
            unset($input['address']['address_line2']);
        }

        return $this->respond($this->kyc->submitProfile($request->user(), $input));
    }

    private function respond(Result $result): JsonResponse
    {
        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }
}
