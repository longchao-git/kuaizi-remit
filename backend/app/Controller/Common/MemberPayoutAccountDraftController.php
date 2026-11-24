<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Service\Remittance\MemberPayoutAccountDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 会员本人收款资料草稿，核验适配完成前不得用于付款。 */
final class MemberPayoutAccountDraftController extends AbstractController
{
    public function __construct(private MemberPayoutAccountDraftService $drafts) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return $this->success($this->drafts->page((int) $request->user()->getKey(), (int) ($input['page'] ?? 1))->getData());
    }

    public function save(Request $request, string $reference): JsonResponse
    {
        $input = $request->validate([
            'payout_method' => ['required', 'in:bank,wechat'],
            'first_name' => ['required', 'string', 'max:64', 'not_regex:/[\x00-\x1f\x7f]/'],
            'last_name' => ['required', 'string', 'max:64', 'not_regex:/[\x00-\x1f\x7f]/'],
            'account' => ['nullable', 'string', 'max:64', 'not_regex:/[\x00-\x1f\x7f]/'],
            'revision' => ['nullable', 'uuid'],
        ]);
        $result = $this->drafts->save((int) $request->user()->getKey(), strtolower($reference), $input);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }
}
