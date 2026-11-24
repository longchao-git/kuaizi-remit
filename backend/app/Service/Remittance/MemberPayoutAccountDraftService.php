<?php

namespace App\Service\Remittance;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Remittance\MemberPayoutAccountDraft;
use App\Repository\Remittance\MemberClosureRepository;
use App\Repository\Remittance\MemberPayoutAccountDraftRepository;
use App\Repository\System\UserRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/** 仅收集待核验资料；输出始终脱敏，不创建上游账户或开通支付能力。 */
final class MemberPayoutAccountDraftService
{
    /** 复用会员及注销状态检查，所有草稿变更在同一会员事务内完成。 */
    public function __construct(
        private MemberPayoutAccountDraftRepository $drafts,
        private UserRepository $users,
        private MemberClosureRepository $closures,
        private DatabaseManager $database,
    ) {}

    /** 分页仅查询本人草稿，并逐条生成脱敏视图。 */
    public function page(int $member, int $page): Result
    {
        $rows = $this->drafts->page($member, $page);

        return Result::success(PaginationPayload::from(array_map($this->present(...), $rows->items()), $rows->total(), $page, $rows->perPage())->toArray());
    }

    /** 会员锁防止与注销冲突，版本号防止旧页面覆盖刚修改的收款资料。 */
    public function save(int $member, string $reference, array $input): Result
    {
        return $this->database->transaction(function () use ($member, $reference, $input): Result {
            $user = $this->users->lockMember($member);
            if ($user === null || $user->member_closed_at !== null || $this->closures->approved($member)) {
                return Result::fail('账号当前无法修改收款资料', 409);
            }
            $draft = $this->drafts->lock($reference);
            if ($draft !== null && (int) $draft->member_id !== $member) {
                return Result::fail('收款资料不存在', 404);
            }
            if (($draft === null && ! empty($input['revision']))
                || ($draft !== null && $draft->revision !== ($input['revision'] ?? null))) {
                return Result::fail('收款资料已更新，请刷新后重新编辑', 409);
            }
            $account = trim($input['account'] ?? '');
            if ($account === '' && $draft !== null && $draft->payout_method !== $input['payout_method']) {
                return Result::fail('更换收款方式时请重新填写收款账号', 422);
            }
            $account = $account !== '' ? $account : ($draft?->details['account'] ?? '');
            if ($account === '') {
                return Result::fail('请填写收款账号', 422);
            }
            $saved = $this->drafts->save($draft, [
                'reference' => $reference, 'member_id' => $member, 'payout_method' => $input['payout_method'],
                'details' => ['first_name' => trim($input['first_name']), 'last_name' => trim($input['last_name']), 'account' => $account],
                'revision' => (string) Str::uuid(),
            ]);

            return Result::success($this->present($saved));
        });
    }

    /** 账号保留末四位便于识别；短账号全遮盖，响应不携带原始账号。 */
    private function present(MemberPayoutAccountDraft $draft): array
    {
        $details = $draft->details;
        $account = $details['account'];

        return [
            'reference' => $draft->reference, 'revision' => $draft->revision, 'payout_method' => $draft->payout_method,
            'first_name' => $details['first_name'], 'last_name' => $details['last_name'],
            'masked_account' => mb_strlen($account) > 4 ? '****'.mb_substr($account, -4) : '****',
            'status' => 'pending_verification', 'can_pay' => false, 'updated_at' => $draft->updated_at->toISOString(),
        ];
    }
}
