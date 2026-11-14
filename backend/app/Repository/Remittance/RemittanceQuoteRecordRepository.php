<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\RemittanceQuoteRecord;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/** @extends AbstractRepository<RemittanceQuoteRecord> */
final class RemittanceQuoteRecordRepository extends AbstractRepository
{
    protected function model(): RemittanceQuoteRecord
    {
        return new RemittanceQuoteRecord;
    }

    public function record(int $memberId, array $snapshot): RemittanceQuoteRecord
    {
        return $this->query()->create([
            'reference' => (string) Str::uuid(),
            'member_id' => $memberId,
            'revision_id' => $snapshot['rule_revision_id'],
            'snapshot' => $snapshot,
        ]);
    }

    public function page(int $page, ?string $reference, ?int $memberId): LengthAwarePaginator
    {
        $query = $this->query()->orderByDesc('id');
        if ($reference !== null) {
            $query->where('reference', strtolower($reference));
        }
        if ($memberId !== null) {
            $query->where('member_id', $memberId);
        }

        return $query->paginate(20, page: $page);
    }

    public function findByReference(string $reference): ?RemittanceQuoteRecord
    {
        return $this->query()->where('reference', strtolower($reference))->first();
    }

    /** 会员只能维护自己的草稿，重复保存保留第一次的时间。 */
    public function saveDraft(int $member, string $reference): ?RemittanceQuoteRecord
    {
        $query = $this->query()->where('member_id', $member)->where('reference', strtolower($reference));
        $query->where('snapshot->mode', 'local_estimate');
        (clone $query)->whereNull('draft_saved_at')->update(['draft_saved_at' => now()]);

        return $query->first();
    }

    public function drafts(int $member, int $page): LengthAwarePaginator
    {
        return $this->query()->where('member_id', $member)->whereNotNull('draft_saved_at')
            ->orderByDesc('draft_saved_at')->orderByDesc('id')->paginate(20, page: $page);
    }

    public function removeDraft(int $member, string $reference): void
    {
        $this->query()->where('member_id', $member)->where('reference', strtolower($reference))
            ->whereNotNull('draft_saved_at')->update(['draft_saved_at' => null]);
    }
}
