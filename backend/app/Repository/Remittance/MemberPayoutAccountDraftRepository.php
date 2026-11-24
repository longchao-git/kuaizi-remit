<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\MemberPayoutAccountDraft;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 草稿独立存储，不能被正式支付适配器当作已核验账户读取。
 *
 * @extends AbstractRepository<MemberPayoutAccountDraft>
 */
final class MemberPayoutAccountDraftRepository extends AbstractRepository
{
    protected function model(): MemberPayoutAccountDraft
    {
        return new MemberPayoutAccountDraft;
    }

    public function page(int $member, int $page): LengthAwarePaginator
    {
        return $this->query()->where('member_id', $member)
            ->orderByDesc('updated_at')->orderBy('reference')->paginate(20, page: $page);
    }

    public function lock(string $reference): ?MemberPayoutAccountDraft
    {
        return $this->query()->lockForUpdate()->find($reference);
    }

    public function save(?MemberPayoutAccountDraft $draft, array $fields): MemberPayoutAccountDraft
    {
        $draft ??= new MemberPayoutAccountDraft;
        $draft->forceFill($fields)->saveOrFail();

        return $draft;
    }
}
